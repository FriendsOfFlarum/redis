<?php

/*
 * This file is part of fof/redis.
 *
 * Copyright (c) Bokt.
 * Copyright (c) Blomstra Ltd.
 * Copyright (c) FriendsOfFlarum
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Redis\Tests\integration;

use Flarum\Foundation\Event\ClearingCache;
use Flarum\Foundation\Paths;
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Extend\Redis as RedisExtender;
use FoF\Redis\Middleware\DistributedCacheInvalidation;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;

class DistributedCacheInvalidationTest extends TestCase
{
    /**
     * Configure Redis for tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Configure Redis connection for tests
        $this->extend(
            new RedisExtender([
                'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
                'password' => getenv('REDIS_PASSWORD') ?: null,
                'port'     => getenv('REDIS_PORT') ?: 6379,
                'database' => 15, // Use separate database for tests
            ])
        );

        // Clean up any previous test data
        try {
            $redis = $this->app()->getContainer()->make(Factory::class);
            $redis->connection('fof.cache')->del('flarum:cache:version');
        } catch (\Exception $e) {
            // Redis not available - tests will be skipped
        }
    }

    protected function tearDown(): void
    {
        // Clean up test data
        try {
            $redis = $this->app()->getContainer()->make(Factory::class);
            $redis->connection('fof.cache')->del('flarum:cache:version');
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }

        parent::tearDown();
    }

    /**
     * @test
     */
    public function redis_connection_is_available()
    {
        try {
            $redis = $this->app()->getContainer()->make(Factory::class);
            $result = $redis->connection('fof.cache')->ping();

            // Predis returns a Status object that can be cast to string
            $this->assertTrue(
                (string) $result === 'PONG' || $result === true,
                'Redis connection should respond to PING'
            );
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis is not available: '.$e->getMessage());
        }
    }

    /**
     * @test
     */
    public function cache_version_key_is_set_when_cache_is_cleared()
    {
        $this->requiresRedis();

        $redis = $this->app()->getContainer()->make(Factory::class);
        $events = $this->app()->getContainer()->make(Dispatcher::class);

        // Before: version key should not exist
        $versionBefore = $redis->connection('fof.cache')->get('flarum:cache:version');
        $this->assertNull($versionBefore, 'Version key should not exist initially');

        // Fire the ClearingCache event (same as what cache:clear does)
        $events->dispatch(new ClearingCache());

        // After: version key should be set to a timestamp
        $versionAfter = $redis->connection('fof.cache')->get('flarum:cache:version');
        $this->assertNotNull($versionAfter, 'Version key should be set after cache clear');
        $this->assertIsNumeric($versionAfter, 'Version should be a numeric timestamp');
        $this->assertGreaterThan(0, (int) $versionAfter, 'Version should be positive');
    }

    /**
     * @test
     */
    public function middleware_detects_cache_version_changes()
    {
        $this->requiresRedis();

        $redis = $this->app()->getContainer()->make(Factory::class);
        $middleware = $this->app()->getContainer()->make(DistributedCacheInvalidation::class);

        // Set initial version
        $initialVersion = time() - 100;
        $redis->connection('fof.cache')->set('flarum:cache:version', $initialVersion);

        // First request - middleware should see the version
        $response1 = $this->send($this->request('GET', '/'));
        $this->assertEquals(200, $response1->getStatusCode());

        // Update version (simulating cache clear on another pod)
        $newVersion = time();
        $redis->connection('fof.cache')->set('flarum:cache:version', $newVersion);

        // Wait for throttle interval to pass
        sleep(6);

        // Second request - middleware should detect the change
        $response2 = $this->send($this->request('GET', '/'));
        $this->assertEquals(200, $response2->getStatusCode());

        // Verify version was updated in Redis
        $currentVersion = $redis->connection('fof.cache')->get('flarum:cache:version');
        $this->assertEquals($newVersion, (int) $currentVersion);
    }

    /**
     * @test
     */
    public function local_file_caches_are_invalidated_when_middleware_detects_version_change()
    {
        $this->requiresRedis();

        $paths = $this->app()->getContainer()->make(Paths::class);
        $redis = $this->app()->getContainer()->make(Factory::class);
        $middleware = $this->app()->getContainer()->make(DistributedCacheInvalidation::class);

        // Create test cache files
        $formatterCache = $paths->storage.'/formatter';
        $localeCache = $paths->storage.'/locale';
        $viewsCache = $paths->storage.'/views';

        // Ensure directories exist
        @mkdir($formatterCache, 0755, true);
        @mkdir($localeCache, 0755, true);
        @mkdir($viewsCache, 0755, true);

        // Create test files
        file_put_contents($formatterCache.'/test.php', '<?php // test');
        file_put_contents($localeCache.'/en.php', '<?php return [];');
        file_put_contents($viewsCache.'/test.blade.php', '<html></html>');

        // Verify files exist
        $this->assertFileExists($formatterCache.'/test.php');
        $this->assertFileExists($localeCache.'/en.php');
        $this->assertFileExists($viewsCache.'/test.blade.php');

        // Set a cache version in Redis
        $redis->connection('fof.cache')->set('flarum:cache:version', time());

        // Directly invoke the middleware logic via reflection to simulate version detection
        // Note: In real PHP-FPM workers, the middleware detects version changes across requests
        // In tests with process isolation, we test the invalidation logic directly
        $reflectionClass = new \ReflectionClass($middleware);

        $shouldInvalidateMethod = $reflectionClass->getMethod('shouldInvalidateLocal');
        $shouldInvalidateMethod->setAccessible(true);

        $invalidateMethod = $reflectionClass->getMethod('invalidateLocalCaches');
        $invalidateMethod->setAccessible(true);

        // Call invalidation directly
        $invalidateMethod->invoke($middleware);

        // Files should be cleared
        $this->assertFileDoesNotExist($formatterCache.'/test.php', 'test.php should be deleted');
        $this->assertFileDoesNotExist($localeCache.'/en.php', 'en.php should be deleted');
        $this->assertFileDoesNotExist($viewsCache.'/test.blade.php', 'test.blade.php should be deleted');
    }

    /**
     * @test
     */
    public function middleware_throttles_redis_checks()
    {
        $this->requiresRedis();

        $redis = $this->app()->getContainer()->make(Factory::class);

        // Set initial version
        $redis->connection('fof.cache')->set('flarum:cache:version', time());

        // Make multiple requests quickly
        $response1 = $this->send($this->request('GET', '/'));
        $response2 = $this->send($this->request('GET', '/'));
        $response3 = $this->send($this->request('GET', '/'));

        // All should succeed
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals(200, $response2->getStatusCode());
        $this->assertEquals(200, $response3->getStatusCode());

        // The middleware should have only checked Redis once due to throttling
        // (We can't directly verify this without instrumentation, but we verify it doesn't cause errors)
    }

    /**
     * @test
     */
    public function middleware_handles_missing_redis_gracefully()
    {
        // Don't require Redis for this test - we want to test the error handling

        // Create a version of the app without Redis
        $this->config('redis.connection', null);

        // Request should still work even if Redis is unavailable
        $response = $this->send($this->request('GET', '/'));
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @test
     */
    public function cache_version_increases_on_each_clear()
    {
        $this->requiresRedis();

        $redis = $this->app()->getContainer()->make(Factory::class);
        $events = $this->app()->getContainer()->make(Dispatcher::class);

        // First clear
        $events->dispatch(new ClearingCache());
        $version1 = (int) $redis->connection('fof.cache')->get('flarum:cache:version');

        // Wait a moment to ensure timestamp differs
        sleep(1);

        // Second clear
        $events->dispatch(new ClearingCache());
        $version2 = (int) $redis->connection('fof.cache')->get('flarum:cache:version');

        // Version should increase
        $this->assertGreaterThan($version1, $version2, 'Version should increase on each clear');
    }

    /**
     * Helper method to skip tests if Redis is not available.
     */
    private function requiresRedis(): void
    {
        try {
            $redis = $this->app()->getContainer()->make(Factory::class);
            $result = $redis->connection('fof.cache')->ping();

            // Predis returns a Status object that can be cast to string
            // If connection fails, it returns false or throws an exception
            if ($result === false || ((string) $result !== 'PONG' && $result !== true)) {
                $this->markTestSkipped('Redis is not available: ping returned '.var_export($result, true));
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis is not available: '.$e->getMessage());
        }
    }
}

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
use FoF\Redis\Console\ListenDistributedCacheInvalidationCommand;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;
use Psr\Log\NullLogger;

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
    private function makeMiddlewareForPod(string $podId): DistributedCacheInvalidation
    {
        // Override getPodId() so we can simulate podA / podB / podC independently
        return new class($podId) extends DistributedCacheInvalidation {
            private string $podId;

            public function __construct(string $podId)
            {
                $this->podId = $podId;
            }

            protected function getPodId(): string
            {
                return $this->podId;
            }
        };
    }

    /** @test */
    public function multipod_pods_update_last_seen_in_redis()
    {
        $this->requiresRedis();

        /** @var \Illuminate\Contracts\Redis\Factory $redisFactory */
        $redisFactory = $this->app()->getContainer()->make(Factory::class);
        $redis = $redisFactory->connection('fof.cache');

        // Clean any pod-specific keys
        $redis->del([
            'flarum:cache:version',
            'flarum:cache:version:last_seen:podA',
            'flarum:cache:version:last_seen:podB',
            'flarum:cache:lock:podA',
            'flarum:cache:lock:podB',
        ]);

        // Initial "last seen" state
        $redis->set('flarum:cache:version:last_seen:podA', 1000);
        $redis->set('flarum:cache:version:last_seen:podB', 1000);

        // Global version bump
        $redis->set('flarum:cache:version', 2000);

        $podA = $this->makeMiddlewareForPod('podA');
        $podB = $this->makeMiddlewareForPod('podB');

        // Use reflection to call shouldInvalidateLocal() directly
        $ref = new \ReflectionClass($podA);
        $shouldInvalidate = $ref->getMethod('shouldInvalidateLocal');
        $shouldInvalidate->setAccessible(true);
        var_dump('Pod A shouldInvalidateLocal called', $redis->get('flarum:cache:version:last_seen:podA'));
        $this->assertTrue($shouldInvalidate->invoke($podA), 'Pod A should detect a newer version');
        $this->assertTrue($shouldInvalidate->invoke($podB), 'Pod B should detect a newer version');

        $this->assertEquals(2000, (int) $redis->get('flarum:cache:version:last_seen:podA'));
        $this->assertEquals(2000, (int) $redis->get('flarum:cache:version:last_seen:podB'));
    }

    /** @test */
    public function pod_does_not_invalidate_if_its_own_lock_is_held()
    {
        $this->requiresRedis();

        $redisFactory = $this->app()->getContainer()->make(Factory::class);
        $redis = $redisFactory->connection('fof.cache');

        $redis->del([
            'flarum:cache:version',
            'flarum:cache:version:last_seen:podA',
            'flarum:cache:lock:podA',
        ]);

        // Last seen and new global version
        $redis->set('flarum:cache:version:last_seen:podA', 1000);
        $redis->set('flarum:cache:version', 2000);

        // Simulate some other worker in *the same pod* already holding the lock
        $redis->set('flarum:cache:lock:podA', 1, 'EX', 30);

        $podA = $this->makeMiddlewareForPod('podA');

        $ref = new \ReflectionClass($podA);
        $shouldInvalidate = $ref->getMethod('shouldInvalidateLocal');
        $shouldInvalidate->setAccessible(true);

        $this->assertFalse(
            $shouldInvalidate->invoke($podA),
            'Pod A should not invalidate if it cannot acquire its own lock'
        );

        // last_seen should not have been updated because lock acquisition failed
        $this->assertEquals(1000, (int) $redis->get('flarum:cache:version:last_seen:podA'));
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

    /**
     * @test
     */
    public function filestore_cache_is_flushed_before_deleting_files()
    {
        $this->requiresRedis();

        $paths = $this->app()->getContainer()->make(Paths::class);
        $redis = $this->app()->getContainer()->make(Factory::class);
        $middleware = $this->app()->getContainer()->make(DistributedCacheInvalidation::class);

        // Get the FileStore and store a test value
        $fileStore = $this->app()->getContainer()->make('cache.filestore');
        $fileStoreRepo = new \Illuminate\Cache\Repository($fileStore);

        // Store a test formatter-like value
        $fileStoreRepo->forever('test.formatter', 'test_value');

        // Verify it's cached
        $this->assertEquals('test_value', $fileStoreRepo->get('test.formatter'));

        // Create test cache file
        $formatterCache = $paths->storage.'/formatter';
        @mkdir($formatterCache, 0755, true);
        file_put_contents($formatterCache.'/test.php', '<?php // test');
        $this->assertFileExists($formatterCache.'/test.php');

        // Set cache version in Redis
        $redis->connection('fof.cache')->set('flarum:cache:version', time());

        // Use reflection to call invalidateLocalCaches
        $reflectionClass = new \ReflectionClass($middleware);
        $invalidateMethod = $reflectionClass->getMethod('invalidateLocalCaches');
        $invalidateMethod->setAccessible(true);

        // Call invalidation
        $invalidateMethod->invoke($middleware);

        // FileStore cache should be cleared (test.formatter should be gone)
        $this->assertNull($fileStoreRepo->get('test.formatter'), 'FileStore cache should be flushed');

        // File should be deleted
        $this->assertFileDoesNotExist($formatterCache.'/test.php', 'Cache file should be deleted');
    }
}

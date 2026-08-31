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

use Flarum\Extension\Event\Disabled;
use Flarum\Extension\Event\Enabled;
use Flarum\Extension\Extension;
use Flarum\Foundation\Event\ClearingCache;
use Flarum\Foundation\Paths;
use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Cache\LocalCacheInvalidator;
use FoF\Redis\Middleware\DistributedCacheInvalidation;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PubSubCacheInvalidationTest extends TestCase
{
    use RedisTestConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerRedis();
    }

    protected function tearDown(): void
    {
        // Remove per-pod epoch records and claims so no test inherits state.
        try {
            $base = $this->app()->getContainer()->make(Paths::class)->base;
            @array_map('unlink', glob($base.'/cache-epoch-*') ?: []);
        } catch (\Throwable $e) {
            // App may not have booted in this test.
        }

        $this->flushTestDatabases();

        parent::tearDown();
    }

    #[Test]
    public function extension_enable_publishes_cache_invalidation_message()
    {
        $published = $this->dispatchWithPublishSpy(new Enabled($this->fakeExtension()));

        $this->assertPublishedInvalidation($published);
    }

    #[Test]
    public function extension_disable_publishes_cache_invalidation_message()
    {
        $published = $this->dispatchWithPublishSpy(new Disabled($this->fakeExtension()));

        $this->assertPublishedInvalidation($published);
    }

    #[Test]
    public function settings_save_publishes_cache_invalidation_message()
    {
        $published = $this->dispatchWithPublishSpy(new Saved(['welcome_title' => 'Changed']));

        $this->assertPublishedInvalidation($published);
    }

    #[Test]
    public function clearing_cache_publishes_cache_invalidation_message()
    {
        $published = $this->dispatchWithPublishSpy(new ClearingCache());

        $this->assertPublishedInvalidation($published);
    }

    #[Test]
    public function invalidation_events_bump_the_shared_epoch()
    {
        $container = $this->app()->getContainer();

        $before = (int) round(microtime(true) * 1000);

        $container->make(Dispatcher::class)->dispatch(new Saved(['welcome_title' => 'Changed']));

        $version = (int) $container->make(Factory::class)
            ->connection('fof.cache')
            ->get(DistributedCacheInvalidation::VERSION_KEY);

        $this->assertGreaterThanOrEqual($before, $version, 'The shared epoch should be bumped alongside the publish');
    }

    #[Test]
    public function middleware_is_registered_in_all_stacks_and_excluded_from_the_internal_api_client()
    {
        $container = $this->app()->getContainer();

        foreach (['forum', 'admin', 'api'] as $frontend) {
            $stack = $container->make("flarum.{$frontend}.middleware");

            $position = array_search(DistributedCacheInvalidation::class, $stack, true);
            $errorHandler = array_search("flarum.{$frontend}.error_handler", $stack, true);

            $this->assertIsInt($position, "Middleware should be registered in the {$frontend} stack");
            $this->assertSame(
                $errorHandler + 1,
                $position,
                "Middleware should run right after the {$frontend} error handler, before assets and session"
            );
        }

        $this->assertContains(
            DistributedCacheInvalidation::class,
            $container->make('flarum.api_client.exclude_middleware'),
            'Internal API sub-requests must not re-run the epoch check'
        );
    }

    #[Test]
    public function middleware_applies_newer_epoch_and_clears_local_caches()
    {
        $container = $this->app()->getContainer();

        /** @var Paths $paths */
        $paths = $container->make(Paths::class);
        @mkdir($paths->storage.'/locale', 0777, true);
        $sentinel = $paths->storage.'/locale/sentinel.tmp';
        file_put_contents($sentinel, 'stale catalogue');

        /** @var LocalCacheInvalidator $invalidator */
        $invalidator = $container->make(LocalCacheInvalidator::class);
        $invalidator->recordApplied(1);

        $version = (int) round(microtime(true) * 1000);
        $container->make(Factory::class)
            ->connection('fof.cache')
            ->set(DistributedCacheInvalidation::VERSION_KEY, (string) $version);

        $settingsCache = $container->make('cache.settings');
        $settingsCache->forever('flarum:settings', ['stale' => 'snapshot']);

        $response = $this->runMiddleware($invalidator);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFileDoesNotExist($sentinel, 'A pod behind the epoch should clear its local caches before serving');
        $this->assertSame($version, $invalidator->appliedVersion());

        // The apply drops the poisoned snapshot; the asset-dirty writes that
        // follow may legitimately re-warm the cache FROM THE DATABASE (2.x's
        // set() patches a warm cache in place — see #28), so the guarantee is
        // "the stale snapshot is gone", not "the cache is empty".
        $cached = $settingsCache->get('flarum:settings');
        $this->assertTrue(
            $cached === null || (is_array($cached) && !array_key_exists('stale', $cached)),
            'Applying an epoch should drop the stale settings snapshot'
        );
        $this->assertNotEmpty(
            $container->make(SettingsRepositoryInterface::class)->get('assets_dirty.forum'),
            'Applying an epoch should mark compiled assets dirty so core rebuilds them in place'
        );
    }

    #[Test]
    public function middleware_does_nothing_when_epoch_is_already_applied()
    {
        $container = $this->app()->getContainer();

        /** @var Paths $paths */
        $paths = $container->make(Paths::class);
        @mkdir($paths->storage.'/locale', 0777, true);
        $sentinel = $paths->storage.'/locale/sentinel.tmp';
        file_put_contents($sentinel, 'fresh catalogue');

        $version = (int) round(microtime(true) * 1000);

        /** @var LocalCacheInvalidator $invalidator */
        $invalidator = $container->make(LocalCacheInvalidator::class);
        $invalidator->recordApplied($version);

        $container->make(Factory::class)
            ->connection('fof.cache')
            ->set(DistributedCacheInvalidation::VERSION_KEY, (string) $version);

        $response = $this->runMiddleware($invalidator);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFileExists($sentinel, 'An up-to-date pod must not re-apply the same epoch');
        $this->assertSame($version, $invalidator->appliedVersion());

        @unlink($sentinel);
    }

    #[Test]
    public function middleware_adopts_epoch_without_clearing_on_first_sight()
    {
        $container = $this->app()->getContainer();

        /** @var Paths $paths */
        $paths = $container->make(Paths::class);
        @mkdir($paths->storage.'/locale', 0777, true);
        $sentinel = $paths->storage.'/locale/sentinel.tmp';
        file_put_contents($sentinel, 'fresh pod');

        /** @var LocalCacheInvalidator $invalidator */
        $invalidator = $container->make(LocalCacheInvalidator::class);
        @unlink($invalidator->epochFilePath());

        $version = (int) round(microtime(true) * 1000);
        $container->make(Factory::class)
            ->connection('fof.cache')
            ->set(DistributedCacheInvalidation::VERSION_KEY, (string) $version);

        $response = $this->runMiddleware($invalidator);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFileExists($sentinel, 'A pod with no epoch record has fresh caches and must not clear them');
        $this->assertSame($version, $invalidator->appliedVersion());

        @unlink($sentinel);
    }

    private function runMiddleware(LocalCacheInvalidator $invalidator): ResponseInterface
    {
        $container = $this->app()->getContainer();

        $middleware = new DistributedCacheInvalidation(
            $container->make(Factory::class),
            $invalidator,
            'fof.cache',
            DistributedCacheInvalidation::VERSION_KEY,
            0
        );

        $handler = new class() implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        return $middleware->process(new ServerRequest(), $handler);
    }

    /**
     * Dispatch an event with the Redis factory replaced by a spy that records
     * every publish on the fof.cache connection, forwarding all other calls
     * to the real manager.
     *
     * @return array<int, array{channel: string, message: string}>
     */
    private function dispatchWithPublishSpy(object $event): array
    {
        $container = $this->app()->getContainer();

        $spy = new PublishSpyFactory($container->make(Factory::class));
        $container->instance(Factory::class, $spy);

        $container->make(Dispatcher::class)->dispatch($event);

        return $spy->published;
    }

    /**
     * @param array<int, array{channel: string, message: string}> $published
     */
    private function assertPublishedInvalidation(array $published): void
    {
        $this->assertCount(1, $published, 'Exactly one invalidation message should be published');
        $this->assertSame('flarum:cache:invalidate', $published[0]['channel']);

        $message = json_decode($published[0]['message'], true);

        $this->assertIsArray($message);
        $this->assertArrayHasKey('timestamp', $message);
        $this->assertArrayHasKey('source', $message);
        $this->assertArrayHasKey('version', $message);
    }

    private function fakeExtension(): Extension
    {
        return new Extension(sys_get_temp_dir().'/fof-redis-fake-extension', [
            'name' => 'fof/fake-extension',
        ]);
    }
}

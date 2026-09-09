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
use Flarum\Locale\LocaleManager;
use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Cache\LocalCacheInvalidator;
use FoF\Redis\Middleware\DistributedCacheInvalidation;
use Illuminate\Cache\Repository;
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
        // Remove per-pod epoch records and claims, and the sentinel files the
        // apply test seeds, so no test inherits state.
        try {
            $paths = $this->app()->getContainer()->make(Paths::class);
            @array_map('unlink', glob($paths->base.'/cache-epoch-*') ?: []);
            @array_map('unlink', glob($paths->storage.'/locale/catalogue.*.sentinel.php*') ?: []);
            @array_map('unlink', array_filter([
                $paths->storage.'/formatter/Renderer_sentinel.php',
                $paths->storage.'/views/sentinel.php',
                $paths->public.'/assets/forum.js',
                $paths->public.'/assets/rev-manifest.json',
            ], 'file_exists'));
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

    /**
     * The catalogues are deleted by core, from markDirty() — this extension no
     * longer deletes them itself, because since core 2.0 a catalogue carries a
     * `.revision` sidecar that CatalogueCache compares per request, so a pod
     * that never received a message detects staleness on its own.
     *
     * What core does not do is tell OPcache, and a catalogue is a PHP file
     * Symfony rewrites at the same path. So the file list must still be
     * captured before the deletion and handed to the OPcache pass — this is
     * what asserts that ordering, which the apply test above cannot: it passes
     * whether the deletion came from core or from here.
     */
    #[Test]
    public function the_opcache_pass_sees_the_catalogues_captured_before_core_deleted_them()
    {
        $container = $this->app()->getContainer();

        /** @var Paths $paths */
        $paths = $container->make(Paths::class);

        @mkdir($paths->storage.'/locale', 0777, true);
        $catalogue = $paths->storage.'/locale/catalogue.en.sentinel.php';
        file_put_contents($catalogue, '<?php return [];');

        $invalidator = new class($container, $paths, $container->make(LocaleManager::class)) extends LocalCacheInvalidator {
            /** @var list<string> */
            public array $invalidated = [];

            protected function invalidateOpcache(array $files): void
            {
                $this->invalidated = $files;
            }
        };

        $invalidator->invalidate();

        $this->assertContains(
            $catalogue,
            $invalidator->invalidated,
            'The list captured before the deletion must reach the OPcache pass, or nothing is invalidated'
        );
        $this->assertFileDoesNotExist($catalogue, 'Core deletes the catalogue via markDirty()');
    }

    #[Test]
    public function middleware_applies_newer_epoch_and_clears_local_caches()
    {
        $container = $this->app()->getContainer();

        /** @var Paths $paths */
        $paths = $container->make(Paths::class);
        // Only the locale catalogues may be deleted by an apply. Seed them the
        // way Symfony actually names them — `catalogue.<locale>.<hash>.php`
        // plus its `.meta` sibling — so this exercises the real glob and the
        // OPcache pass over the PHP file, not just a placeholder that any
        // wildcard would match.
        @mkdir($paths->storage.'/locale', 0777, true);
        $sentinel = $paths->storage.'/locale/catalogue.en.sentinel.php';
        file_put_contents($sentinel, '<?php return [];');
        $sentinelMeta = $sentinel.'.meta';
        file_put_contents($sentinelMeta, 'stale catalogue metadata');

        // The file cache itself must be forgotten (the serialized formatter
        // lives there under `flarum.formatter`)...
        $fileCache = new Repository($container->make('cache.filestore'));
        $fileCache->forever('flarum.formatter', 'stale serialized formatter');

        // ...but everything a concurrent request may still be using must
        // survive: the formatter's renderer class files (deleting them
        // mid-unserialize yields an incomplete object and a 500), the compiled
        // Blade views, and the shared compiled assets with their revision
        // manifest (2.x marks them dirty; it never flushes them). The manifest
        // is seeded with a revision so a re-introduced flush would have
        // something to act on.
        @mkdir($paths->storage.'/formatter', 0777, true);
        $formatterSentinel = $paths->storage.'/formatter/Renderer_sentinel.php';
        file_put_contents($formatterSentinel, '<?php class Renderer_sentinel {}');

        @mkdir($paths->storage.'/views', 0777, true);
        $viewSentinel = $paths->storage.'/views/sentinel.php';
        file_put_contents($viewSentinel, '<?php /* compiled view */');

        @mkdir($paths->public.'/assets', 0777, true);
        $manifest = $paths->public.'/assets/rev-manifest.json';
        $manifestBefore = '{"forum.js":"sentinel"}';
        file_put_contents($manifest, $manifestBefore);
        $assetSentinel = $paths->public.'/assets/forum.js';
        file_put_contents($assetSentinel, '/* compiled asset */');

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
        $this->assertFileDoesNotExist($sentinelMeta, 'The catalogue metadata must go with the catalogue, or Symfony reads a stale .meta');
        $this->assertSame($version, $invalidator->appliedVersion());
        $this->assertNull($fileCache->get('flarum.formatter'), 'An apply must forget the cached serialized formatter');

        $this->assertFileExists($formatterSentinel, 'An apply must not delete formatter class files a concurrent request may be unserializing');
        $this->assertFileExists($viewSentinel, 'An apply must not delete compiled Blade views');
        $this->assertFileExists($assetSentinel, 'An apply must not delete the shared compiled assets');
        $this->assertSame($manifestBefore, file_get_contents($manifest), 'An apply must not rewrite the shared revision manifest');

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
        $sentinel = $paths->storage.'/locale/catalogue.en.sentinel.php';
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
        $sentinel = $paths->storage.'/locale/catalogue.en.sentinel.php';
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

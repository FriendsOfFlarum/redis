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
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Cache\LocalCacheInvalidator;
use FoF\Redis\Console\CacheSubscribeCommand;
use FoF\Redis\Extend\Redis as RedisExtender;
use FoF\Redis\Middleware\DistributedCacheInvalidation;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class PubSubCacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extend(
            new RedisExtender([
                'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
                'password' => getenv('REDIS_PASSWORD') ?: null,
                'port'     => getenv('REDIS_PORT') ?: 6379,
                'database' => 15,
                'pubsub'   => [
                    'enabled'   => true,
                    'autostart' => false,
                ],
            ])
        );
    }

    /**
     * @test
     */
    public function redis_connection_is_available()
    {
        try {
            $redis = $this->app()->getContainer()->make(Factory::class);
            $result = $redis->connection('fof.cache')->ping();

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
    public function cache_subscribe_command_is_registered()
    {
        $commands = $this->app()->getContainer()->make('flarum.console.commands');

        $this->assertContains(
            CacheSubscribeCommand::class,
            $commands,
            'cache:subscribe command should be registered when cache is enabled'
        );
    }

    /**
     * @test
     */
    public function extension_enable_publishes_cache_invalidation_message()
    {
        $this->requiresRedis();

        $published = $this->dispatchWithPublishSpy(new Enabled($this->fakeExtension()));

        $this->assertPublishedInvalidation($published);
    }

    /**
     * @test
     */
    public function extension_disable_publishes_cache_invalidation_message()
    {
        $this->requiresRedis();

        $published = $this->dispatchWithPublishSpy(new Disabled($this->fakeExtension()));

        $this->assertPublishedInvalidation($published);
    }

    /**
     * @test
     */
    public function settings_save_publishes_cache_invalidation_message()
    {
        $this->requiresRedis();

        $published = $this->dispatchWithPublishSpy(new Saved(['welcome_title' => 'Changed']));

        $this->assertPublishedInvalidation($published);
    }

    /**
     * @test
     */
    public function clearing_cache_publishes_cache_invalidation_message()
    {
        $this->requiresRedis();

        $published = $this->dispatchWithPublishSpy(new ClearingCache());

        $this->assertPublishedInvalidation($published);
    }

    /**
     * @test
     */
    public function invalidation_events_bump_the_shared_epoch()
    {
        $this->requiresRedis();

        $container = $this->app()->getContainer();

        $before = (int) round(microtime(true) * 1000);

        $container->make(Dispatcher::class)->dispatch(new Saved(['welcome_title' => 'Changed']));

        $version = (int) $container->make(Factory::class)
            ->connection('fof.cache')
            ->get(DistributedCacheInvalidation::VERSION_KEY);

        $this->assertGreaterThanOrEqual($before, $version, 'The shared epoch should be bumped alongside the publish');
    }

    /**
     * @test
     */
    public function middleware_applies_newer_epoch_and_clears_local_caches()
    {
        $this->requiresRedis();

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
        $this->assertNull(
            $settingsCache->get('flarum:settings'),
            'Applying an epoch should also drop the shared settings cache, killing stale snapshots'
        );
    }

    /**
     * @test
     */
    public function middleware_adopts_epoch_without_clearing_on_first_sight()
    {
        $this->requiresRedis();

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

    private function requiresRedis(): void
    {
        try {
            $redis = $this->app()->getContainer()->make(Factory::class);
            $result = $redis->connection('fof.cache')->ping();

            if ($result === false || ((string) $result !== 'PONG' && $result !== true)) {
                $this->markTestSkipped('Redis is not available: ping returned '.var_export($result, true));
            }
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis is not available: '.$e->getMessage());
        }
    }
}

/**
 * Redis factory decorator that records publishes on the fof.cache connection
 * and forwards everything else to the real manager.
 */
class PublishSpyFactory implements Factory
{
    /** @var array<int, array{channel: string, message: string}> */
    public array $published = [];

    public function __construct(protected Factory $inner)
    {
    }

    public function connection($name = null)
    {
        $connection = $this->inner->connection($name);

        if ($name === 'fof.cache') {
            return new PublishSpyConnection($connection, $this);
        }

        return $connection;
    }
}

class PublishSpyConnection
{
    public function __construct(
        protected $inner,
        protected PublishSpyFactory $spy
    ) {
    }

    public function publish($channel, $message)
    {
        $this->spy->published[] = [
            'channel' => (string) $channel,
            'message' => (string) $message,
        ];

        return $this->inner->publish($channel, $message);
    }

    public function __call($method, $arguments)
    {
        return $this->inner->{$method}(...$arguments);
    }
}

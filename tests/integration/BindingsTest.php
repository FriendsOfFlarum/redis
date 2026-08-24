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

use Flarum\Testing\integration\TestCase;
use FoF\Redis\Extend\Redis;
use FoF\Redis\Overrides\RedisManager;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Redis\Factory;
use PHPUnit\Framework\Attributes\Test;

class BindingsTest extends TestCase
{
    use RedisTestConfig;

    protected function tearDown(): void
    {
        $this->flushTestDatabases();

        parent::tearDown();
    }

    #[Test]
    public function the_redis_manager_is_bound_and_aliased_to_the_factory_contract()
    {
        $this->flushTestDatabases();
        $this->registerRedis();

        $container = $this->app()->getContainer();

        $this->assertInstanceOf(RedisManager::class, $container->make(RedisManager::class));
        // Factory contract resolves to the same overridden manager.
        $this->assertInstanceOf(RedisManager::class, $container->make(Factory::class));
        $this->assertSame($container->make(RedisManager::class), $container->make(Factory::class));
    }

    #[Test]
    public function the_manager_selects_a_client_matching_the_environment()
    {
        $this->flushTestDatabases();
        $this->registerRedis();

        $connection = $this->app()->getContainer()->make(Factory::class)->connection('fof.cache');
        $client = $connection->client();

        // Bindings picks phpredis when ext-redis is loaded, predis otherwise.
        if (extension_loaded('redis')) {
            $this->assertInstanceOf(\Redis::class, $client);
        } else {
            $this->assertInstanceOf(\Predis\Client::class, $client);
        }
    }

    /**
     * The queue connection fof/redis binds must carry a name. Core names its
     * own driver but not one an extension replaces, and a null name flows into
     * the pause/resume bookkeeping and the WorkerIdle event — both of which
     * expect a string — crashing `queue:pause`. Naming it here (as Horizon
     * does) keeps the connection valid regardless of the core version.
     */
    #[Test]
    public function the_queue_connection_is_named()
    {
        $this->flushTestDatabases();
        $this->registerRedis();

        $queue = $this->app()->getContainer()->make('flarum.queue.connection');

        // Core may wrap the driver (RoutingQueue) and delegate the name down to
        // it; assert on the driver fof/redis actually bound.
        $driver = method_exists($queue, 'getDriver') ? $queue->getDriver() : $queue;

        $this->assertSame('flarum', $driver->getConnectionName());
    }

    #[Test]
    public function disable_prevents_a_service_binding_from_being_overridden()
    {
        // Disable the cache service; the cache store must NOT become a Redis
        // store (the queue/session/settings services still register).
        $this->flushTestDatabases();
        $this->extend(
            (new Redis($this->redisConfig()))
                ->useDatabaseWith('queue', $this->testQueueDb)
                ->useDatabaseWith('session', $this->testSessionDb)
                ->useDatabaseWith('settings', $this->testSettingsDb)
                ->disable(['cache'])
        );

        $store = $this->app()->getContainer()->make('cache.store')->getStore();

        $this->assertNotInstanceOf(
            RedisStore::class,
            $store,
            'a disabled cache service should not override the cache store with Redis'
        );
    }

    #[Test]
    public function server_info_works_when_the_cache_service_is_disabled()
    {
        // RedisServerInfo is registered whenever ANY Redis service is enabled,
        // but it defaults to the 'fof.cache' connection — which only the Cache
        // provider registers. With cache disabled but other services on, a
        // naive RedisServerInfo would query an unregistered connection and sit
        // in a permanent error state, breaking `redis:info` on an otherwise
        // healthy install. It must resolve against a connection that actually
        // exists.
        $this->flushTestDatabases();
        $this->extend(
            (new Redis($this->redisConfig()))
                ->useDatabaseWith('queue', $this->testQueueDb)
                ->useDatabaseWith('session', $this->testSessionDb)
                ->useDatabaseWith('settings', $this->testSettingsDb)
                ->disable(['cache'])
        );

        $info = $this->app()->getContainer()->make(\FoF\Redis\RedisServerInfo::class);

        $this->assertNotEmpty($info->section(), 'server info should read INFO even with cache disabled');
        $this->assertFalse($info->hasError(), 'server info must not be in an error state when cache is disabled: '.$info->error());
        $this->assertNotSame('unknown', $info->version());
    }

    #[Test]
    public function the_extender_forwards_and_chains_configuration_calls()
    {
        // useDatabaseWith / disable are Configuration methods reached through
        // the extender's __call; they must return the extender for chaining,
        // not the underlying Configuration.
        $extender = new Redis($this->redisConfig());

        $this->assertInstanceOf(Redis::class, $extender->useDatabaseWith('cache', 5));
        $this->assertInstanceOf(Redis::class, $extender->disable(['session']));
    }
}

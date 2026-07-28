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

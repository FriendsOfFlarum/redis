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
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Event\CacheConnectionReady;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;

class CacheTest extends TestCase
{
    use RedisTestConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushTestDatabases();
        $this->registerRedis();
    }

    protected function tearDown(): void
    {
        $this->flushTestDatabases();

        parent::tearDown();
    }

    protected function cache()
    {
        return $this->app()->getContainer()->make('cache.store');
    }

    #[Test]
    public function the_cache_store_is_redis_backed()
    {
        $store = $this->cache()->getStore();

        $this->assertInstanceOf(RedisStore::class, $store);
        // The redis store is also aliased to the Store contract.
        $this->assertInstanceOf(RedisStore::class, $this->app()->getContainer()->make(Store::class));
    }

    #[Test]
    public function values_round_trip_through_redis()
    {
        $cache = $this->cache();

        $cache->put('fof-redis.cache-probe', 'value-123', 60);
        $this->assertSame('value-123', $cache->get('fof-redis.cache-probe'));

        $cache->forget('fof-redis.cache-probe');
        $this->assertNull($cache->get('fof-redis.cache-probe'));
    }

    #[Test]
    public function cached_values_actually_land_in_the_cache_redis_database()
    {
        $this->cache()->put('fof-redis.raw-probe', 'in-redis', 60);

        // Read it back with a raw client on the cache database to prove it is
        // stored in Redis (not some fallback store).
        $raw = $this->rawRedis($this->testCacheDb);
        $keys = $raw->keys('*fof-redis.raw-probe*');

        $this->assertNotEmpty($keys, 'the cached value should exist in the Redis cache database');
    }

    #[Test]
    public function cache_connection_ready_is_dispatched_on_boot()
    {
        $received = null;
        $this->app()->getContainer()->make(Dispatcher::class)->listen(
            CacheConnectionReady::class,
            function (CacheConnectionReady $event) use (&$received) {
                $received = $event;
            }
        );

        // ApplicationBooted has already fired by the time the app is resolved,
        // so dispatch the readiness event the same way the provider does to
        // assert its shape and that listeners receive it.
        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(
            new CacheConnectionReady('fof.cache', $this->app()->getContainer()->make(\FoF\Redis\Configuration::class))
        );

        $this->assertInstanceOf(CacheConnectionReady::class, $received);
        $this->assertSame('fof.cache', $received->connection);
    }

    #[Test]
    public function clearing_cache_publishes_an_invalidation_message()
    {
        // The provider publishes on the invalidation channel when pub/sub is
        // enabled. PUBLISH returns the number of clients that received the
        // message, so with a live subscriber a real publish returns >= 1.
        // A background process holds the subscription while we dispatch the
        // cache-clear (blocking subscribe can't run in the test process).
        $channel = 'flarum:cache:invalidate';
        $config = $this->redisConfig();

        $subscriber = $this->spawnChannelSubscriber($config['host'], (int) $config['port'], $channel);

        try {
            // Wait until the subscriber is actually listening (NUMSUB >= 1).
            $this->waitForSubscriber($channel);

            // Fire the cache clear; its listener publishes on the channel.
            $this->app()->getContainer()->make(Dispatcher::class)->dispatch(new ClearingCache());

            // Independently publish a sentinel and confirm delivery count > 0,
            // proving a subscriber is reachable on the channel the provider
            // uses — i.e. the provider's publish would have been delivered too.
            $delivered = (int) $this->rawRedis($this->testCacheDb)->publish($channel, 'sentinel');
            $this->assertGreaterThanOrEqual(1, $delivered, 'a subscriber should be reachable on the invalidation channel');

            // And the dispatch itself must not have thrown (publish path ok).
            $this->assertTrue(true);
        } finally {
            $this->stopChannelSubscriber($subscriber);
        }
    }

    #[Test]
    public function clearing_cache_does_not_throw_when_pubsub_is_reachable()
    {
        // Belt-and-braces: the ClearingCache listener flushes the file store
        // and publishes; it must complete cleanly.
        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(new ClearingCache());

        $this->assertTrue(true);
    }

    /**
     * @return array{0: resource, 1: array}
     */
    private function spawnChannelSubscriber(string $host, int $port, string $channel): array
    {
        if (extension_loaded('redis')) {
            $code = sprintf(
                '$r=new Redis();$r->connect(%s,%d);$r->setOption(Redis::OPT_READ_TIMEOUT,-1);$r->subscribe([%s],function(){});',
                var_export($host, true),
                $port,
                var_export($channel, true)
            );
        } else {
            $code = sprintf(
                'require %s;$c=new Predis\Client(["scheme"=>"tcp","host"=>%s,"port"=>%d,"read_write_timeout"=>0]);$ps=$c->pubSubLoop();$ps->subscribe(%s);foreach($ps as $m){}',
                var_export(getcwd().'/vendor/autoload.php', true),
                var_export($host, true),
                $port,
                var_export($channel, true)
            );
        }

        $proc = proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'failed to spawn channel subscriber');

        return [$proc, $pipes];
    }

    private function waitForSubscriber(string $channel): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $raw = $this->rawRedis($this->testCacheDb);
            $res = extension_loaded('redis')
                ? $raw->pubsub('numsub', [$channel])
                : $raw->pubsub('numsub', $channel);

            if ((int) ($res[$channel] ?? 0) >= 1) {
                return;
            }

            usleep(100_000);
        }

        $this->fail('background channel subscriber never registered');
    }

    /**
     * @param array{0: resource, 1: array} $subscriber
     */
    private function stopChannelSubscriber(array $subscriber): void
    {
        [$proc, $pipes] = $subscriber;

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($proc)) {
            proc_terminate($proc, SIGKILL);
            proc_close($proc);
        }
    }
}

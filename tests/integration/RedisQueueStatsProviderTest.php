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

use Flarum\Queue\QueueStatsProvider;
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Extend\Redis;
use FoF\Redis\Queue\RedisQueueStatsProvider;
use PHPUnit\Framework\Attributes\Test;

class RedisQueueStatsProviderTest extends TestCase
{
    use RedisTestConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerRedis();

        // Start from a clean queue database. Tests share one Redis instance, so
        // residue from a previous test would otherwise skew the counts here.
        $this->flushQueueDatabase();
    }

    /**
     * Register the Redis extender. Tests share one Redis instance, so use high,
     * dedicated databases (13-15): a local dev stack may run a `queue:work`
     * worker on the usual queue database on the same server, which would
     * consume the jobs these tests push and make assertions flaky. (CI uses a
     * dedicated Redis with no worker, so this is belt-and-suspenders there.).
     *
     * @param array $queueConfig extra keys merged into the `queue` config block
     */
    protected function registerRedis(array $queueConfig = []): void
    {
        $config = $this->redisConfig();
        $config['queue'] = array_merge($config['queue'] ?? [], $queueConfig);

        $this->extend(
            (new Redis($config))
                ->useDatabaseWith('cache', 13)
                ->useDatabaseWith('queue', 14)
                ->useDatabaseWith('session', 15)
                ->useDatabaseWith('settings', 12)
        );
    }

    protected function tearDown(): void
    {
        $this->flushQueueDatabase();

        parent::tearDown();
    }

    protected function flushQueueDatabase(): void
    {
        // Flush directly via a raw client rather than through the container, so
        // this does NOT boot the app. Booting here would lock in the extenders
        // registered so far, preventing a test from registering extra queue
        // config (which must happen before boot).
        try {
            $config = $this->redisConfig();
            $host = $config['host'];
            $port = (int) $config['port'];

            if (extension_loaded('redis')) {
                $client = new \Redis();
                $client->connect($host, $port);
                $client->select(14); // the dedicated test queue database
                $client->flushdb();
                $client->close();
            } else {
                $client = new \Predis\Client(['scheme' => 'tcp', 'host' => $host, 'port' => $port, 'database' => 14]);
                $client->flushdb();
            }
        } catch (\Throwable $e) {
            // Redis not reachable — a redis-dependent test will fail loudly.
        }
    }

    protected function stats(): QueueStatsProvider
    {
        return $this->app()->getContainer()->make(QueueStatsProvider::class);
    }

    #[Test]
    public function it_overrides_core_with_the_redis_stats_provider()
    {
        $this->assertInstanceOf(RedisQueueStatsProvider::class, $this->stats());
    }

    #[Test]
    public function totals_has_the_expected_keys_and_starts_empty()
    {
        $totals = $this->stats()->totals();

        $this->assertSame(['pending', 'reserved', 'failed'], array_keys($totals));
        $this->assertSame(0, $totals['pending']);
        $this->assertSame(0, $totals['reserved']);
        $this->assertSame(0, $totals['failed']);
    }

    #[Test]
    public function queues_reports_each_known_queue()
    {
        $queues = $this->stats()->queues();

        // Core's default known-queues registry is ['default'].
        $this->assertArrayHasKey('default', $queues);
        $this->assertSame(['pending', 'reserved'], array_keys($queues['default']));
    }

    #[Test]
    public function totals_pending_reflects_jobs_pushed_onto_redis()
    {
        $queue = $this->app()->getContainer()->make('flarum.queue.connection');

        $queue->pushRaw(json_encode(['uuid' => 'p1', 'displayName' => 'X']), 'default');
        $queue->pushRaw(json_encode(['uuid' => 'p2', 'displayName' => 'X']), 'default');

        $totals = $this->stats()->totals();
        $this->assertSame(2, $totals['pending']);
        $this->assertSame(2, $this->stats()->queues()['default']['pending']);
    }

    #[Test]
    public function totals_failed_reflects_the_redis_failer()
    {
        $failer = $this->app()->getContainer()->make('queue.failer');
        $failer->log('redis', 'default', json_encode(['uuid' => 'f1', 'displayName' => 'X']), new \RuntimeException('x'));

        $this->assertSame(1, $this->stats()->totals()['failed']);
    }

    #[Test]
    public function configured_named_queues_are_registered_and_reported()
    {
        // A site that runs `queue:work --queue=emails,default` declares those
        // names in the fof/redis `queue.queues` config; fof/redis appends them
        // to core's known-queues registry so admin tooling (the dashboard,
        // per-queue pause) covers them.
        $this->registerRedis(['queues' => ['emails', 'notifications']]);

        // The core registry now includes the configured names plus 'default'.
        $known = $this->app()->getContainer()->make('flarum.queue.queues');
        $this->assertEqualsCanonicalizing(['default', 'emails', 'notifications'], $known);

        // And the stats provider reports a bucket for each.
        $queues = $this->stats()->queues();
        $this->assertArrayHasKey('default', $queues);
        $this->assertArrayHasKey('emails', $queues);
        $this->assertArrayHasKey('notifications', $queues);
    }

    #[Test]
    public function totals_sum_pending_across_named_queues()
    {
        $this->registerRedis(['queues' => ['emails']]);

        $queue = $this->app()->getContainer()->make('flarum.queue.connection');
        $queue->pushRaw(json_encode(['uuid' => 'd1', 'displayName' => 'X']), 'default');
        $queue->pushRaw(json_encode(['uuid' => 'e1', 'displayName' => 'X']), 'emails');
        $queue->pushRaw(json_encode(['uuid' => 'e2', 'displayName' => 'X']), 'emails');

        $totals = $this->stats()->totals();
        $this->assertSame(3, $totals['pending']);

        $queues = $this->stats()->queues();
        $this->assertSame(1, $queues['default']['pending']);
        $this->assertSame(2, $queues['emails']['pending']);
    }
}

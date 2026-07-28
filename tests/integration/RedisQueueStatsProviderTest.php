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
use FoF\Redis\Queue\RedisQueueStatsProvider;
use PHPUnit\Framework\Attributes\Test;

class RedisQueueStatsProviderTest extends TestCase
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
    public function delayed_jobs_are_counted_as_pending()
    {
        // Core's DatabaseQueueStatsProvider counts every not-yet-reserved job
        // as pending — including delayed ones, which sit in queue_jobs with a
        // future available_at and NULL reserved_at. The Redis provider must
        // match: a delayed job (in the ':delayed' zset) has to show up in
        // pending, otherwise the dashboard reports an idle queue while jobs are
        // scheduled. Counting only the ready list (llen) misses them.
        $queue = $this->app()->getContainer()->make('flarum.queue.connection');

        // One ready now (ready list)...
        $queue->pushRaw(json_encode(['uuid' => 'ready', 'displayName' => 'X']), 'default');
        // ...and two scheduled for later. A delayed job lives in the
        // 'queues:default:delayed' sorted set, scored by its available-at time
        // (exactly what RedisQueue::laterRaw writes). Insert them directly since
        // laterRaw is protected.
        $raw = $this->rawRedis($this->testQueueDb);
        $availableAt = time() + 3600;
        $raw->zadd('queues:default:delayed', $availableAt, json_encode(['uuid' => 'later-1', 'displayName' => 'X']));
        $raw->zadd('queues:default:delayed', $availableAt, json_encode(['uuid' => 'later-2', 'displayName' => 'X']));

        $totals = $this->stats()->totals();
        $this->assertSame(3, $totals['pending'], 'pending must include the 1 ready + 2 delayed jobs');
        $this->assertSame(3, $this->stats()->queues()['default']['pending']);
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

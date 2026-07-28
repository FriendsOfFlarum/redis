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
use Illuminate\Contracts\Redis\Factory;
use PHPUnit\Framework\Attributes\Test;

class RedisQueueStatsProviderTest extends TestCase
{
    use RedisTestConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extend(
            (new Redis($this->redisConfig()))
                ->useDatabaseWith('cache', 1)
                ->useDatabaseWith('queue', 2)
                ->useDatabaseWith('session', 3)
        );
    }

    protected function tearDown(): void
    {
        try {
            $this->app()->getContainer()->make(Factory::class)->connection('default')->flushdb();
        } catch (\Throwable $e) {
            // ignored — a redis-dependent test will fail loudly on its own
        }

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
}

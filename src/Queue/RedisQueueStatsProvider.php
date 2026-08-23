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

namespace FoF\Redis\Queue;

use Flarum\Queue\QueueStatsProvider;
use Flarum\Queue\RoutingQueue;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\RedisQueue;

/**
 * Feeds core's queue dashboard when the queue runs on Redis.
 *
 * Core's default {@see \Flarum\Queue\DatabaseQueueStatsProvider} reads the
 * `queue_jobs` table, which is empty when fof/redis has taken over the queue
 * connection — so without this the dashboard would report an idle queue. We
 * re-bind `QueueStatsProvider` to this class from the Redis queue provider.
 *
 * Counts come from the Redis queue's own public size methods (which speak to
 * Redis via the configured client, so they work under both phpredis and
 * predis). Since Flarum 2.0.0-rc.6, core decorates `flarum.queue.connection`
 * with a {@see RoutingQueue} wrapper, so the injected queue is unwrapped to the
 * concrete driver before those methods are reached — the same way core's own
 * QueueServiceProvider and ApplicationInfoProvider see through it.
 *
 * Failed jobs are read through the bound failer, exactly as core does — under
 * fof/redis that is our own RedisFailedJobProvider, so failures live in Redis
 * rather than the `queue_failed_jobs` table.
 */
class RedisQueueStatsProvider implements QueueStatsProvider
{
    /**
     * The concrete queue driver, with any core wrapper unwrapped.
     */
    protected Queue $driver;

    public function __construct(
        protected Queue $queue,
        protected FailedJobProviderInterface $failer,
        /** @var list<string> */
        protected array $queues
    ) {
        $this->driver = $this->unwrap($queue);
    }

    /**
     * Resolve the concrete driver behind core's queue decorator.
     *
     * Core wraps whatever is bound to `flarum.queue.connection` — including our
     * Redis queue — in a RoutingQueue so pushes can be routed to a named queue.
     * The wrapper is not a RedisQueue, so the size lookups below have to run
     * against the driver it wraps or every count reads as zero. `class_exists`
     * keeps this working on cores from before the wrapper existed.
     */
    protected function unwrap(Queue $queue): Queue
    {
        while (class_exists(RoutingQueue::class) && $queue instanceof RoutingQueue) {
            $driver = $queue->getDriver();

            if ($driver === $queue) {
                break;
            }

            $queue = $driver;
        }

        return $queue;
    }

    public function totals(): array
    {
        $pending = 0;
        $reserved = 0;

        foreach ($this->queues as $queue) {
            $pending += $this->pendingSize($queue);
            $reserved += $this->reservedSize($queue);
        }

        return [
            'pending'  => $pending,
            'reserved' => $reserved,
            'failed'   => count($this->failer->ids()),
        ];
    }

    public function queues(): array
    {
        $queues = [];

        foreach ($this->queues as $queue) {
            $queues[$queue] = [
                'pending'  => $this->pendingSize($queue),
                'reserved' => $this->reservedSize($queue),
            ];
        }

        return $queues;
    }

    /**
     * Jobs that are queued but not yet reserved by a worker.
     *
     * This counts both the ready list AND the delayed set, to match core's
     * DatabaseQueueStatsProvider, which counts every `queue_jobs` row with a
     * NULL `reserved_at` as pending — delayed jobs included (they sit in the
     * same table with a future `available_at`). Counting only the ready list
     * (llen) would report an idle queue while jobs are scheduled for later.
     */
    protected function pendingSize(string $queue): int
    {
        if (!$this->driver instanceof RedisQueue) {
            return 0;
        }

        return (int) $this->driver->pendingSize($queue) + (int) $this->driver->delayedSize($queue);
    }

    /**
     * Jobs a worker has reserved but not yet finished (the reserved zset).
     */
    protected function reservedSize(string $queue): int
    {
        return $this->driver instanceof RedisQueue
            ? (int) $this->driver->reservedSize($queue)
            : 0;
    }
}

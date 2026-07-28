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

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\Failed\PrunableFailedJobProvider;
use Illuminate\Support\Carbon;

/**
 * Stores failed jobs in Redis instead of the database.
 *
 * Core only wires a real failer ({@see \Flarum\Queue\DatabaseUuidFailedJobProvider})
 * for the database queue; every other driver gets a NullFailedJobProvider that
 * silently discards failures. An admin running fof/redis has deliberately moved
 * cache, sessions and the queue off the database to reduce its load — so their
 * failed jobs belong in Redis too, not back in the DB. This provider keeps them
 * there while remaining a drop-in for core's failed-job management UI (the
 * FailedJobs service calls all()/find()/forget()/ids()).
 *
 * Storage layout (in the queue's Redis connection/database):
 *   - hash `{prefix}:failed:{uuid}` — one field per column (connection, queue,
 *     payload, exception, failed_at).
 *   - sorted set `{prefix}:failed` — member = uuid, score = failed_at unix ts;
 *     gives ordering (newest first), count, id listing and prune-by-age.
 */
class RedisFailedJobProvider implements CountableFailedJobProvider, FailedJobProviderInterface, PrunableFailedJobProvider
{
    protected string $indexKey;
    protected string $jobKeyPrefix;

    /**
     * @param  Factory  $redis        the Redis connection factory
     * @param  string   $connectionName  the queue's Redis connection name
     * @param  int|null $ttl          seconds to keep a failed job before it
     *                                expires; null/0 keeps it until forgotten.
     *                                A TTL also makes the entry eligible for
     *                                eviction under a `volatile-*` maxmemory
     *                                policy, so failures can be reclaimed under
     *                                memory pressure while untagged keys (live
     *                                queue jobs, cache) are protected.
     * @param  string   $prefix       key prefix for the index/hashes
     */
    public function __construct(
        protected Factory $redis,
        protected string $connectionName,
        protected ?int $ttl = null,
        string $prefix = 'queues:failed'
    ) {
        $this->indexKey = $prefix;
        $this->jobKeyPrefix = $prefix.':';
    }

    /**
     * Log a failed job into Redis.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @param  \Throwable  $exception
     * @return string|int|null
     */
    public function log($connection, $queue, $payload, $exception)
    {
        // Reuse the job's own uuid when present (Flarum/Laravel stamp one on
        // dispatch) so retries and tracing line up; fall back to a fresh one.
        $uuid = json_decode($payload, true)['uuid'] ?? (string) \Illuminate\Support\Str::uuid();

        $failedAt = Carbon::now();
        $jobKey = $this->jobKeyPrefix.$uuid;

        $redis = $this->connection();

        $redis->hmset($jobKey, [
            'id'         => $uuid,
            'connection' => $connection ?? '',
            'queue'      => $queue,
            'payload'    => $payload,
            'exception'  => (string) mb_convert_encoding((string) $exception, 'UTF-8'),
            'failed_at'  => $failedAt->getTimestamp(),
        ]);

        // A TTL bounds memory growth and, under a volatile-* eviction policy,
        // lets Redis reclaim old failures under memory pressure. The index
        // entry outlives the hash if the hash expires first; reads tolerate
        // that and clean the dangling id.
        if ($this->ttl !== null && $this->ttl > 0) {
            $redis->expire($jobKey, $this->ttl);
        }

        $redis->zadd($this->indexKey, $failedAt->getTimestamp(), $uuid);

        return $uuid;
    }

    /**
     * Get the IDs of all of the failed jobs (optionally for one queue),
     * newest first.
     *
     * @param  string|null  $queue
     * @return array
     */
    public function ids($queue = null)
    {
        $redis = $this->connection();
        $ids = [];

        foreach ($redis->zrevrange($this->indexKey, 0, -1) as $id) {
            $recordQueue = $redis->hget($this->jobKeyPrefix.$id, 'queue');

            // Hash expired (TTL) but index entry lingered — drop the dangling
            // id. A missing field is `null` on predis and `false` on phpredis,
            // so test for either rather than an exact type.
            if ($recordQueue === null || $recordQueue === false) {
                $redis->zrem($this->indexKey, $id);

                continue;
            }

            if ($queue === null || $recordQueue === $queue) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Get a list of all of the failed jobs, newest first.
     *
     * @return array
     */
    public function all()
    {
        $redis = $this->connection();
        $jobs = [];

        foreach ($redis->zrevrange($this->indexKey, 0, -1) as $id) {
            $job = $this->find($id);

            // Hash expired (TTL) but index entry lingered — drop the dangling id.
            if ($job === null) {
                $redis->zrem($this->indexKey, $id);

                continue;
            }

            $jobs[] = $job;
        }

        return $jobs;
    }

    /**
     * Get a single failed job as an object matching the DB failer's shape.
     *
     * @param  mixed  $id
     * @return object|null
     */
    public function find($id)
    {
        $record = $this->connection()->hgetall($this->jobKeyPrefix.$id);

        if (empty($record)) {
            return null;
        }

        return (object) $record;
    }

    /**
     * Delete a single failed job from storage.
     *
     * @param  mixed  $id
     * @return bool
     */
    public function forget($id)
    {
        $connection = $this->connection();

        $removed = (int) $connection->zrem($this->indexKey, $id);
        $connection->del($this->jobKeyPrefix.$id);

        return $removed > 0;
    }

    /**
     * Flush all (or old) failed jobs from storage.
     *
     * @param  int|null  $hours
     * @return void
     */
    public function flush($hours = null)
    {
        if ($hours === null) {
            foreach ($this->connection()->zrange($this->indexKey, 0, -1) as $id) {
                $this->connection()->del($this->jobKeyPrefix.$id);
            }

            $this->connection()->del($this->indexKey);

            return;
        }

        $this->prune(Carbon::now()->subHours($hours));
    }

    /**
     * Prune failed jobs older than the given date.
     *
     * @return int
     */
    public function prune(\DateTimeInterface $before)
    {
        $connection = $this->connection();

        // Redis score-range bounds are strings ('-inf', '(5', a numeric
        // literal, …), so pass the cutoff timestamp as a string.
        $cutoff = (string) $before->getTimestamp();

        $ids = $connection->zrangebyscore($this->indexKey, '-inf', $cutoff);

        foreach ($ids as $id) {
            $connection->del($this->jobKeyPrefix.$id);
        }

        if (! empty($ids)) {
            $connection->zremrangebyscore($this->indexKey, '-inf', $cutoff);
        }

        return count($ids);
    }

    /**
     * Count the failed jobs (optionally for one connection/queue).
     *
     * @param  string|null  $connection
     * @param  string|null  $queue
     * @return int
     */
    public function count($connection = null, $queue = null)
    {
        if ($connection === null && $queue === null) {
            return (int) $this->connection()->zcard($this->indexKey);
        }

        return count(array_filter($this->all(), function ($job) use ($connection, $queue) {
            return ($connection === null || $job->connection === $connection)
                && ($queue === null || $job->queue === $queue);
        }));
    }

    protected function connection(): Connection
    {
        return $this->redis->connection($this->connectionName);
    }
}

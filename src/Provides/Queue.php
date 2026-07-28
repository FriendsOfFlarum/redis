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

namespace FoF\Redis\Provides;

use Flarum\Queue\QueueStatsProvider;
use FoF\Redis\Configuration;
use FoF\Redis\Overrides\RedisManager;
use FoF\Redis\Overrides\RedisQueue;
use FoF\Redis\Queue\RedisFailedJobProvider;
use FoF\Redis\Queue\RedisQueueStatsProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Arr;

class Queue extends Provider
{
    private string $connection = 'default';

    public function __invoke(Configuration $configuration, Container $container): void
    {
        $container->resolving(Factory::class, function (Factory $manager) use ($configuration) {
            /** @var RedisManager $manager */
            $manager->addConnection($this->connection, $configuration->toArray());
        });

        // Bind as a singleton, matching core's own `flarum.queue.connection`
        // binding. A transient binding would open a fresh Redis connection on
        // every resolution — wasteful, and it means a component that resolves
        // the queue separately (e.g. the stats provider) reads through a
        // brand-new connection that may not yet observe a write made on another
        // connection microseconds earlier. One shared connection avoids both.
        $container->singleton('flarum.queue.connection', function ($container) use ($configuration) {
            $config = Arr::get($configuration->toArray(), 'queue', []);

            /** @var RedisManager $manager */
            $manager = $container->make(Factory::class);

            $queue = new RedisQueue(
                $manager,
                'default',
                $this->connection,
                Arr::get($config, 'retry_after', 60),
                Arr::get($config, 'block_for', 1),
                Arr::get($config, 'after_commit', false)
            );
            $queue->setContainer($container);

            return $queue;
        });

        // Register any additional named queues the site runs.
        //
        // Redis (like Horizon) supports multiple named queues — a worker is
        // started with `queue:work --queue=high,default,low` and jobs are
        // routed to a queue via `AbstractJob::$onQueue`. There is no cheap way
        // to enumerate queues in Redis (KEYS is O(n) and blocks the server), so
        // core keeps a registry of known queue names (`flarum.queue.queues`,
        // default `['default']`) that admin tooling — the queue dashboard and
        // per-queue pause — reads. A site declares its queue names in the
        // `queue.queues` config; we append them here so those tools cover them.
        $container->extend('flarum.queue.queues', function ($queues) use ($configuration) {
            $config = Arr::get($configuration->toArray(), 'queue', []);
            $configured = Arr::get($config, 'queues', []);

            return array_values(array_unique(array_merge(
                is_array($queues) ? $queues : ['default'],
                ['default'],
                (array) $configured
            )));
        });

        // Store failed jobs in Redis rather than losing them.
        //
        // Core only wires a real failer for the database queue; every other
        // driver gets a NullFailedJobProvider that discards failures. An admin
        // on fof/redis has deliberately moved load off the database, so their
        // failures belong in Redis too — not back in the DB. Overriding the
        // `queue.failer` binding here keeps them in Redis and makes core's
        // failed-job management UI (view/retry/delete) work under Redis.
        $container->singleton('queue.failer', function ($container) use ($configuration) {
            $config = Arr::get($configuration->toArray(), 'queue', []);

            return new RedisFailedJobProvider(
                $container->make(Factory::class),
                $this->connection,
                // Default: keep a week of failure history, then let it expire.
                // Set queue.failed_ttl to 0/null to keep failures indefinitely.
                Arr::get($config, 'failed_ttl', 604800)
            );
        });

        // Feed core's queue dashboard from Redis instead of the (now empty)
        // queue_jobs table. Only meaningful on core builds that ship the
        // QueueStatsProvider contract; guarded so older cores are unaffected.
        if (interface_exists(QueueStatsProvider::class)) {
            $container->singleton(QueueStatsProvider::class, function ($container) {
                return new RedisQueueStatsProvider(
                    $container->make('flarum.queue.connection'),
                    $container->make('queue.failer'),
                    $container->make('flarum.queue.queues')
                );
            });
        }
    }
}

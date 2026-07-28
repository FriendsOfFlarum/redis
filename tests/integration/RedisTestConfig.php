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

use FoF\Redis\Extend\Redis;

/**
 * Shared Redis setup for integration tests.
 *
 * Points at a Redis-compatible server on 127.0.0.1:6379 by default (what the
 * reusable backend CI workflow exposes when `enable_redis` is set); overridable
 * via REDIS_HOST / REDIS_PORT for local runs.
 *
 * All services are pinned to dedicated high databases (12-15). This matters
 * because tests may run against a Redis that is ALSO serving a live dev forum:
 * a local dev stack keeps its cache/queue/session/settings on low databases
 * and may run a `queue:work` worker. A test that used those databases would
 * consume the worker's jobs or overwrite the dev forum's live data — flushing
 * the settings cache once made a dev forum's extensions appear disabled. Every
 * service is pinned here so no service can fall back onto a live database.
 * (CI uses a dedicated Redis with no worker, so this is belt-and-suspenders
 * there.)
 */
trait RedisTestConfig
{
    protected int $testCacheDb = 13;
    protected int $testQueueDb = 14;
    protected int $testSessionDb = 15;
    protected int $testSettingsDb = 12;

    protected function redisConfig(): array
    {
        return [
            'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
            'password' => null,
            'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
            // Base database for any service without an explicit override. Kept
            // high so an unpinned service can never land on a live database.
            'database' => 12,
            'prefix'   => '',
            'pubsub'   => [
                'enabled'   => true,
                'autostart' => false,
            ],
            'persistent' => false,
        ];
    }

    /**
     * Register the Redis extender with all services pinned to test databases.
     *
     * @param array $queueConfig extra keys merged into the `queue` config block
     */
    protected function registerRedis(array $queueConfig = []): void
    {
        $config = $this->redisConfig();
        $config['queue'] = array_merge($config['queue'] ?? [], $queueConfig);

        $this->extend(
            (new Redis($config))
                ->useDatabaseWith('cache', $this->testCacheDb)
                ->useDatabaseWith('queue', $this->testQueueDb)
                ->useDatabaseWith('session', $this->testSessionDb)
                ->useDatabaseWith('settings', $this->testSettingsDb)
        );
    }

    /**
     * Flush the test databases via a raw client, WITHOUT booting the app.
     *
     * Going through the container would boot the application and lock in the
     * extenders registered so far, which would stop a test from registering
     * extra config (that must happen before boot). Using a raw client keeps
     * the flush independent of app state, and never touches a live database.
     */
    protected function flushTestDatabases(): void
    {
        $config = $this->redisConfig();
        $host = $config['host'];
        $port = (int) $config['port'];
        $databases = [$this->testCacheDb, $this->testQueueDb, $this->testSessionDb, $this->testSettingsDb];

        try {
            if (extension_loaded('redis')) {
                $client = new \Redis();
                $client->connect($host, $port);
                foreach ($databases as $db) {
                    $client->select($db);
                    $client->flushdb();
                }
                $client->close();
            } else {
                foreach ($databases as $db) {
                    (new \Predis\Client(['scheme' => 'tcp', 'host' => $host, 'port' => $port, 'database' => $db]))->flushdb();
                }
            }
        } catch (\Throwable $e) {
            // Redis not reachable — a redis-dependent test will fail loudly.
        }
    }

    /**
     * Open a raw client against a specific test database, matching the client
     * fof/redis itself would select (phpredis when the extension is loaded,
     * predis otherwise). Useful for asserting on stored data directly.
     *
     * @return \Redis|\Predis\Client
     */
    protected function rawRedis(int $database)
    {
        $config = $this->redisConfig();

        if (extension_loaded('redis')) {
            $client = new \Redis();
            $client->connect($config['host'], (int) $config['port']);
            $client->select($database);

            return $client;
        }

        return new \Predis\Client([
            'scheme'   => 'tcp',
            'host'     => $config['host'],
            'port'     => (int) $config['port'],
            'database' => $database,
        ]);
    }
}

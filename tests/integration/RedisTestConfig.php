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

/**
 * Shared Redis connection config for integration tests.
 *
 * Points at a Redis-compatible server on 127.0.0.1:6379 by default, which is
 * exactly what the reusable backend CI workflow exposes when `enable_redis`
 * is set. Overridable via REDIS_HOST / REDIS_PORT for local runs.
 */
trait RedisTestConfig
{
    protected function redisConfig(): array
    {
        return [
            'host'        => getenv('REDIS_HOST') ?: '127.0.0.1',
            'password'    => null,
            'port'        => (int) (getenv('REDIS_PORT') ?: 6379),
            // Base database for any service without an explicit useDatabaseWith
            // override. Deliberately high: a local dev stack keeps its cache /
            // settings on low databases (0-4) of the same Redis server, and a
            // service that fell back to those would let tests overwrite the dev
            // forum's live data (e.g. the settings cache). 12 is reserved for
            // the suite, distinct from the queue/cache/session test databases.
            'database'    => 12,
            'prefix'      => '',
            'pubsub'      => [
                'enabled'   => true,
                'autostart' => false,
            ],
            'persistent' => false,
        ];
    }
}

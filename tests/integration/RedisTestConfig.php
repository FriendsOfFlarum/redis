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
            'database'    => 1,
            'prefix'      => '',
            'pubsub'      => [
                'enabled'   => true,
                'autostart' => false,
            ],
            'persistent' => false,
        ];
    }
}

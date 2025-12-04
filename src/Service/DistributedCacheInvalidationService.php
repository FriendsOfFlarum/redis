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

namespace FoF\Redis\Service;

use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;

class DistributedCacheInvalidationService
{
    const DEFAULT_CONNECTION = 'fof.cache';

    public function __construct(protected Factory $redisFactory)
    {
    }

    public function notify(): void
    {
        $redisConnection = $this->redisFactory->connection(static::DEFAULT_CONNECTION);
        $redisConnection->set('flarum:cache:version', time());

        //update last seen version for this pod, so it doesn't consider its own notification to clear the cache again in the listener
        $this->updateLastSeenVersion(time());

        //push a message to notify listeners immediately
        $redisConnection->client()->publish('flarum.cache.cleared', json_encode(['timestamp' => time()]));
    }

    public function updateLastSeenVersion(int $timeStamp): void
    {
        $lastSeenKey = 'flarum:cache:version:last_seen:'.$this->getPodId();
        $this->redisFactory->connection(static::DEFAULT_CONNECTION)->set($lastSeenKey, $timeStamp, 'ex', 604800); // expire in 7 days
    }

    public function getLastSeenVersion(): int
    {
        $lastSeenKey = 'flarum:cache:version:last_seen:'.$this->getPodId();

        return (int) $this->getConnection()->get($lastSeenKey);
    }

    public function getPodId(): string
    {
        return gethostname() ?? 'invalid_hostname';
    }

    public function getConnection(): Connection
    {
        return $this->redisFactory->connection(static::DEFAULT_CONNECTION);
    }

    public function createSubscribeConnection(): Connection
    {
        return $this->redisFactory->resolve(static::DEFAULT_CONNECTION);
    }

    public function getGlobalCacheVersion(): int
    {
        return (int) $this->getConnection()->get('flarum:cache:version');
    }
}

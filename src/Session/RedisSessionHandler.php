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

namespace FoF\Redis\Session;

use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Session\CacheBasedSessionHandler;

class RedisSessionHandler extends CacheBasedSessionHandler
{
    public function __sleep(): array
    {
        return ['minutes'];
    }

    public function __wakeup(): void
    {
        // Re-resolve the SESSION store (dropped by __sleep because the Redis
        // client isn't serialisable), not the general cache store. Resolving
        // `cache.store` here would make a woken handler read and write sessions
        // in the cache database — wrong whenever sessions and cache are pinned
        // to different Redis databases. Rebuild the same repository the Session
        // provider wraps around `session.redisstore`.
        $this->cache = new CacheRepository(resolve('session.redisstore'));
    }
}

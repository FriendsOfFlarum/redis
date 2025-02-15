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

use Illuminate\Session\CacheBasedSessionHandler;

class RedisSessionHandler extends CacheBasedSessionHandler
{
    public function __sleep(): array
    {
        return ['minutes'];
    }

    public function __wakeup(): void
    {
        $this->cache = resolve('cache.store');
    }
}

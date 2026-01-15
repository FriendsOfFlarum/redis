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

namespace FoF\Redis\Event;

use FoF\Redis\Configuration;

class CacheConnectionReady
{
    public function __construct(
        public ?string $connection,
        public ?Configuration $configuration
    ) {
    }
}

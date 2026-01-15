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
    /**
     * The name of the Redis connection that is ready.
     *
     * @var string|null
     */
    public $connection;

    /**
     * The configuration of the Redis connection that is ready.
     *
     * @var Configuration|null
     */
    public $configuration;

    public function __construct($connection, $configuration)
    {
        $this->connection = $connection;
        $this->configuration = $configuration;
    }
}

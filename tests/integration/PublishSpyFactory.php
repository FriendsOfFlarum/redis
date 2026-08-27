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

use Illuminate\Contracts\Redis\Factory;

/**
 * Redis factory decorator that records publishes on the fof.cache connection
 * and forwards everything else to the real manager.
 */
class PublishSpyFactory implements Factory
{
    /** @var array<int, array{channel: string, message: string}> */
    public array $published = [];

    public function __construct(protected Factory $inner)
    {
    }

    public function connection($name = null)
    {
        $connection = $this->inner->connection($name);

        if ($name === 'fof.cache') {
            return new PublishSpyConnection($connection, $this);
        }

        return $connection;
    }
}

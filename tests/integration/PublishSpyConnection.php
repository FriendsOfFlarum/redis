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
 * Connection decorator recording publish() calls; every other call is
 * forwarded to the real connection.
 */
class PublishSpyConnection
{
    public function __construct(
        protected $inner,
        protected PublishSpyFactory $spy
    ) {
    }

    public function publish($channel, $message)
    {
        $this->spy->published[] = [
            'channel' => (string) $channel,
            'message' => (string) $message,
        ];

        return $this->inner->publish($channel, $message);
    }

    public function __call($method, $arguments)
    {
        return $this->inner->{$method}(...$arguments);
    }
}

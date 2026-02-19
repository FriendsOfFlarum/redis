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

namespace FoF\Redis\Overrides;

use Illuminate\Queue\RedisQueue as IlluminateQueue;

class RedisQueue extends IlluminateQueue
{
    /**
     * {@inheritdoc}
     */
    public function push($job, $data = '', $queue = null)
    {
        if (is_object($job) && !empty($job->queue) && !$queue) {
            $queue = $job->queue;
        }

        return parent::push($job, $data, $queue);
    }
}

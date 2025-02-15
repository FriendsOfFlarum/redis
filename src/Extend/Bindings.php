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

namespace FoF\Redis\Extend;

use Flarum\Extend\ExtenderInterface;
use Flarum\Extension\Extension;
use FoF\Redis\Overrides\RedisManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory;

class Bindings implements ExtenderInterface
{
    public function extend(Container $container, Extension $extension = null): void
    {
        if (!$container->has(RedisManager::class)) {
            $container->singleton(RedisManager::class, function ($app) {
                return new RedisManager($app, 'predis', []);
            });

            $container->alias(RedisManager::class, Factory::class);
        }
    }
}

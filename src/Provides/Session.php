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

namespace FoF\Redis\Provides;

use FoF\Redis\Configuration;
use FoF\Redis\Overrides\RedisManager;
use FoF\Redis\Session\RedisSessionHandler;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Arr;
use SessionHandlerInterface;

class Session extends Provider
{
    private $connection = 'fof.sessions';

    public function __invoke(Configuration $configuration, Container $container)
    {
        $container->resolving(Factory::class, function (Factory $manager) use ($configuration) {
            /** @var RedisManager $manager */
            $manager->addConnection($this->connection, $configuration->toArray());
        });

        $container->singleton('session.redisstore', function ($container) use ($configuration) {
            /** @var RedisManager $manager */
            $manager = $container->make(Factory::class);

            return new RedisStore(
                $manager,
                Arr::get($configuration->toArray(), 'prefix', ''),
                $this->connection
            );
        });

        $container->singleton('session.handler', function ($container) {
            $config = $container->make(Repository::class);

            return new RedisSessionHandler(
                new CacheRepository($container->make('session.redisstore')),
                $config['session.lifetime'],
            );
        });

        $container->alias('session.handler', SessionHandlerInterface::class);
    }
}

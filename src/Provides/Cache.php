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

use Flarum\Foundation\Event\ClearingCache;
use FoF\Redis\Configuration;
use FoF\Redis\Console\ListenDistributedCacheInvalidationCommand;
use FoF\Redis\Overrides\RedisManager;
use FoF\Redis\Service\DistributedCacheInvalidationService;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Arr;

class Cache extends Provider
{
    protected $commands = [
        ListenDistributedCacheInvalidationCommand::class,
    ];
    private $connection = 'fof.cache';

    public function __invoke(Configuration $configuration, Container $container)
    {
        // register command
        $container->resolving(Factory::class, function (Factory $manager) use ($configuration) {
            /** @var RedisManager $manager */
            $manager->addConnection($this->connection, $configuration->toArray());
        });

        $container->bind('cache.redisstore', function ($container) use ($configuration) {
            /** @var RedisManager $manager */
            $manager = $container->make(Factory::class);

            return new RedisStore(
                $manager,
                Arr::get($configuration->toArray(), 'prefix', ''),
                $this->connection
            );
        });

        $container->extend('cache.store', function ($_, $container) {
            return new Repository($container->make('cache.redisstore'));
        });

        $container->alias('cache.redisstore', Store::class);

        /** @var Dispatcher $events */
        $events = $container->make(Dispatcher::class);
        $events->listen(ClearingCache::class, function (ClearingCache $_) use ($container) {
            // This clears the cache for the text formatter which is stored in file storage
            // this is hardcoded in core because it is autoloaded using spl.
            (new Repository(resolve('cache.filestore')))->flush();

            // Set global cache version for distributed invalidation
            // This signals other instances to invalidate their local caches
            try {
                /** @var DistributedCacheInvalidationService $service */
                $service = $container->make(DistributedCacheInvalidationService::class);
                $service->notify();
            } catch (\Exception $e) {
                // Fail gracefully if Redis is unavailable
            }
        });

        $container->extend('flarum.console.commands', function ($commands) {
            return array_merge($this->commands, $commands);
        });
    }
}

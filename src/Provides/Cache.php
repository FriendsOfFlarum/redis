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
use FoF\Redis\Event\CacheConnectionReady;
use FoF\Redis\Middleware\DistributedCacheInvalidation;
use FoF\Redis\Overrides\RedisManager;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Arr;

class Cache extends Provider
{
    private $connection = 'fof.cache';

    public function __invoke(Configuration $configuration, Container $container)
    {
        $container->resolving(Factory::class, function (Factory $manager) use ($configuration, $container) {
            /** @var RedisManager $manager */
            $manager->addConnection($this->connection, $configuration->toArray());

            /**
             * @var Dispatcher $events
             *
             * This event dispatches very early to notify that the cache connection is ready.
             *
             * In order to listen for this event, you need to register your listener before the cache provider boots.
             * This can be done by using a ServiceProvider to register the listener.
             *
             * @example
             *
             * class CacheReadyProvider extends AbstractServiceProvider
             * {
             *    public function register()
             *    {
             *      $this->container['events']->listen(
             *         CacheConnectionReady::class,
             *         CacheSubscriber::class
             *     );
             *   }
             * }
             */
            $events = $container->make(Dispatcher::class);
            $events->dispatch(
                new CacheConnectionReady($this->connection, $configuration)
            );
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
                /** @var RedisManager $redis */
                $redis = $container->make(Factory::class);
                $redis->connection($this->connection)->set('flarum:cache:version', time());
            } catch (\Exception $e) {
                // Fail gracefully if Redis is unavailable
            }
        });

        foreach (['forum', 'admin', 'api'] as $fontend) {
            $container->extend("flarum.{$fontend}.middleware", function ($existingMiddleware) {
                $existingMiddleware[] = DistributedCacheInvalidation::class;

                return $existingMiddleware;
            });
        }
    }
}

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
use Flarum\Settings\DatabaseSettingsRepository;
use Flarum\Settings\DefaultSettingsRepository;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\Redis\Configuration;
use FoF\Redis\Overrides\RedisManager;
use FoF\Redis\Settings\RedisCacheSettingsRepository;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;

class Settings extends Provider
{
    private string $connection = 'fof.settings';

    public function __invoke(Configuration $configuration, Container $container): void
    {
        $rawConfig = $configuration->toArray();
        $connectionConfig = $rawConfig;
        Arr::forget($connectionConfig, ['pubsub']);

        // Add the settings connection to the Redis manager
        $container->resolving(Factory::class, function (Factory $manager) use ($connectionConfig) {
            /** @var RedisManager $manager */
            $manager->addConnection($this->connection, $connectionConfig);
        });

        // Create a dedicated cache repository for settings using the settings connection
        $container->singleton('cache.settings', function ($container) use ($configuration) {
            /** @var RedisManager $manager */
            $manager = $container->make(Factory::class);

            $store = new RedisStore(
                $manager,
                Arr::get($configuration->toArray(), 'prefix', ''),
                $this->connection
            );

            return new Repository($store);
        });

        // Replace the entire settings repository binding to use Redis caching instead of MemoryCache
        $container->singleton(SettingsRepositoryInterface::class, function (Container $container) {
            $cache = $container->make('cache.settings');

            return new DefaultSettingsRepository(
                new RedisCacheSettingsRepository(
                    new DatabaseSettingsRepository(
                        $container->make(ConnectionInterface::class)
                    ),
                    $cache
                ),
                $container->make('flarum.settings.default')
            );
        });

        // Listen for cache clear events and clear settings cache too
        $container->make(Dispatcher::class)->listen(
            ClearingCache::class,
            function () use ($container) {
                try {
                    $settingsCache = $container->make('cache.settings');
                    $settingsCache->forget('flarum:settings');
                } catch (\Exception $e) {
                    // Fail gracefully if settings cache is unavailable
                }
            }
        );
    }
}

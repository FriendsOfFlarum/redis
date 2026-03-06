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

use Flarum\Extension\Event\Disabled;
use Flarum\Extension\Event\Enabled;
use Flarum\Foundation\Event\ClearingCache;
use Flarum\Settings\DatabaseSettingsRepository;
use Flarum\Settings\DefaultSettingsRepository;
use Flarum\Settings\MemoryCacheSettingsRepository;
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
    private $connection = 'fof.settings';

    public function __invoke(Configuration $configuration, Container $container)
    {
        $rawConfig = $configuration->toArray();
        $connectionConfig = $rawConfig;

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

        // Replace the entire settings repository binding with a three-layer chain:
        // MemoryCacheSettingsRepository  — per-request in-process cache (zero network cost after first read)
        //   └── RedisCacheSettingsRepository  — cross-request Redis cache (one Redis GET per request, 1h TTL)
        //         └── DatabaseSettingsRepository  — source of truth (MySQL, hit only on Redis miss)
        $container->singleton(SettingsRepositoryInterface::class, function (Container $container) {
            $cache = $container->make('cache.settings');

            return new DefaultSettingsRepository(
                new MemoryCacheSettingsRepository(
                    new RedisCacheSettingsRepository(
                        new DatabaseSettingsRepository(
                            $container->make(ConnectionInterface::class)
                        ),
                        $cache
                    )
                ),
                $container->make('flarum.settings.default')
            );
        });

        $invalidateSettingsCache = function () use ($container) {
            try {
                $settingsCache = $container->make('cache.settings');
                $settingsCache->forget('flarum:settings');
            } catch (\Exception $e) {
                // Fail gracefully if settings cache is unavailable
            }
        };

        $dispatcher = $container->make(Dispatcher::class);

        // Clear cached settings when the cache is explicitly cleared.
        $dispatcher->listen(ClearingCache::class, $invalidateSettingsCache);

        // Extension enable/disable writes extensions_enabled via MemoryCacheSettingsRepository
        // (ExtensionManager is resolved before our binding override takes effect), so it bypasses
        // RedisCacheSettingsRepository::set(). Invalidate the Redis key so the next read
        // re-populates from DB, which always has the correct value.
        $dispatcher->listen(Enabled::class, $invalidateSettingsCache);
        $dispatcher->listen(Disabled::class, $invalidateSettingsCache);
    }
}

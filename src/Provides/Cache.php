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
use Flarum\Foundation\Paths;
use FoF\Redis\Configuration;
use FoF\Redis\Event\CacheConnectionReady;
use FoF\Redis\Overrides\RedisManager;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Support\Arr;
use Psr\Log\LoggerInterface;

class Cache extends Provider
{
    private $connection = 'fof.cache';

    public function __invoke(Configuration $configuration, Container $container)
    {
        $rawConfig = $configuration->toArray();
        $pubSubConfig = $this->normalizePubSubConfig(Arr::get($rawConfig, 'pubsub', []));

        $connectionConfig = $rawConfig;
        Arr::forget($connectionConfig, ['pubsub']);

        $container->resolving(Factory::class, function (Factory $manager) use ($connectionConfig) {
            /** @var RedisManager $manager */
            $manager->addConnection($this->connection, $connectionConfig);
        });

        /** @var Dispatcher $events */
        $events = $container->make(Dispatcher::class);
        $bootCallback = function () use ($events, $configuration, $container, $pubSubConfig) {
            $events->dispatch(
                new CacheConnectionReady($this->connection, $configuration)
            );

            if ($pubSubConfig['autostart']) {
                $this->startSubscriber($container, $pubSubConfig);
            }
        };

        if (class_exists(\Flarum\Foundation\Event\ApplicationBooted::class)) { // Flarum >= 1.8.13
            $events->listen(\Flarum\Foundation\Event\ApplicationBooted::class, $bootCallback);
        } else {
            $container->make('flarum')->booted($bootCallback);
        }

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

        $events->listen(ClearingCache::class, function (ClearingCache $_) use ($container, $pubSubConfig) {
            // This clears the cache for the text formatter which is stored in file storage
            // this is hardcoded in core because it is autoloaded using spl.
            (new Repository(resolve('cache.filestore')))->flush();

            try {
                /** @var RedisManager $redis */
                $redis = $container->make(Factory::class);
                if ($pubSubConfig['enabled']) {
                    $message = json_encode([
                        'timestamp' => time(),
                        'source'    => gethostname(),
                        'version'   => time(),
                    ]);

                    $redis->connection($this->connection)->publish($pubSubConfig['channel'], $message);
                }
            } catch (\Exception $e) {
                // Fail gracefully if Redis is unavailable
            }
        });
    }

    private function normalizePubSubConfig(array $config): array
    {
        $enabled = (bool) ($config['enabled'] ?? false);
        $autostart = array_key_exists('autostart', $config) ? (bool) $config['autostart'] : $enabled;

        if ($autostart) {
            $enabled = true;
        }

        return [
            'enabled'        => $enabled,
            'autostart'      => $autostart,
            'channel'        => $config['channel'] ?? 'flarum:cache:invalidate',
            'delay'          => (int) ($config['delay'] ?? 0),
            'spawn_lock_ttl' => (int) ($config['spawn_lock_ttl'] ?? 300),
        ];
    }

    private function startSubscriber(Container $container, array $pubSubConfig): void
    {
        $paths = $container->make(Paths::class);
        $logger = $container->make(LoggerInterface::class);

        $lockFile = $paths->base.'/cache-subscriber.lock';
        $spawnLockFile = $paths->base.'/cache-subscriber.spawn.lock';

        if ($this->isAlreadyRunning($lockFile, $pubSubConfig['spawn_lock_ttl'])) {
            return;
        }

        if (!$this->acquireSpawnLock($spawnLockFile, $pubSubConfig['spawn_lock_ttl'])) {
            return;
        }

        // Pre-create the subscriber lock to prevent other workers from spawning
        // until the subscriber writes its real PID.
        $this->writeSubscriberLock($lockFile);

        $logger->info('[Cache Subscriber] Attempting to auto-start cache subscriber', [
            'pod' => gethostname(),
        ]);

        try {
            $phpBinary = PHP_BINARY;
            $basePath = $paths->base;

            $options = [];
            if ($pubSubConfig['delay'] > 0) {
                $options[] = '--delay='.(int) $pubSubConfig['delay'];
            }
            if (!empty($pubSubConfig['channel'])) {
                $options[] = '--channel='.escapeshellarg($pubSubConfig['channel']);
            }

            $optionString = $options ? ' '.implode(' ', $options) : '';

            $command = sprintf(
                'cd %s && %s flarum cache:subscribe%s > /dev/null 2>&1 & echo $!',
                escapeshellarg($basePath),
                escapeshellarg($phpBinary),
                $optionString
            );

            $pid = trim(shell_exec($command) ?? '');

            if ($pid && is_numeric($pid)) {
                $logger->info('[Cache Subscriber] Successfully spawned cache subscriber', [
                    'pid' => $pid,
                    'pod' => gethostname(),
                ]);
            } else {
                $logger->error('[Cache Subscriber] Failed to spawn: Invalid PID', [
                    'pid_output' => $pid,
                    'pod'        => gethostname(),
                ]);
            }
        } catch (\Exception $e) {
            $logger->error('[Cache Subscriber] Exception while spawning subscriber', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
                'pod'       => gethostname(),
            ]);
            $this->removeSubscriberLock($lockFile);
        } finally {
            $this->releaseSpawnLock($spawnLockFile);
        }
    }

    private function isAlreadyRunning(string $lockFile, int $staleAfterSeconds = 0): bool
    {
        if (!file_exists($lockFile)) {
            return false;
        }

        $pid = (int) file_get_contents($lockFile);

        if ($pid > 0) {
            if ($this->isProcessRunning($pid)) {
                return true;
            }

            @unlink($lockFile);

            return false;
        }

        if ($staleAfterSeconds > 0) {
            $age = time() - (int) @filemtime($lockFile);
            if ($age > $staleAfterSeconds) {
                @unlink($lockFile);

                return false;
            }
        }

        return true;
    }

    private function isProcessRunning(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return file_exists("/proc/$pid");
    }

    private function acquireSpawnLock(string $lockFile, int $staleAfterSeconds): bool
    {
        if (file_exists($lockFile)) {
            $age = time() - (int) @filemtime($lockFile);
            if ($age > $staleAfterSeconds) {
                @unlink($lockFile);
            }
        }

        $handle = @fopen($lockFile, 'x');
        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return true;
    }

    private function writeSubscriberLock(string $lockFile): void
    {
        @file_put_contents($lockFile, '0');
    }

    private function removeSubscriberLock(string $lockFile): void
    {
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    private function releaseSpawnLock(string $lockFile): void
    {
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }
}

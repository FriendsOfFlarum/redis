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
use Flarum\Foundation\Paths;
use Flarum\Settings\Event\Saved;
use FoF\Redis\Cache\LocalCacheInvalidator;
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
use Psr\Log\LoggerInterface;

class Cache extends Provider
{
    private string $connection = 'fof.cache';

    public function __invoke(Configuration $configuration, Container $container): void
    {
        $rawConfig = $configuration->toArray();
        $pubSubConfig = $this->normalizePubSubConfig(
            Arr::get($rawConfig, 'pubsub', []),
            Arr::get($rawConfig, 'prefix', '')
        );

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

        $events->listen(\Flarum\Foundation\Event\ApplicationBooted::class, $bootCallback); // @phpstan-ignore class.notFound

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

        $publishInvalidation = function () use ($container, $pubSubConfig) {
            if (!$pubSubConfig['enabled']) {
                return;
            }

            try {
                /** @var RedisManager $redis */
                $redis = $container->make(Factory::class);

                $version = (int) round(microtime(true) * 1000);

                // Durable epoch for the middleware backstop: pods that miss the
                // pub/sub message (subscriber down) or race its delivery catch
                // up synchronously on their next request.
                $redis->connection($this->connection)
                    ->set($pubSubConfig['version_key'], (string) $version);

                $message = json_encode([
                    'timestamp' => time(),
                    'source'    => gethostname(),
                    'version'   => $version,
                ]);

                $redis->connection($this->connection)->publish($pubSubConfig['channel'], $message);
            } catch (\Exception $e) {
                // Fail gracefully if Redis is unavailable
            }
        };

        $events->listen(ClearingCache::class, function (ClearingCache $_) use ($publishInvalidation) {
            // This clears the cache for the text formatter which is stored in file storage
            // this is hardcoded in core because it is autoloaded using spl.
            (new Repository(resolve('cache.filestore')))->flush();

            $publishInvalidation();
        });

        // Core reacts to extension toggles and settings saves with pod-local
        // invalidation only (compiled assets, locale catalogues) and never
        // dispatches ClearingCache for them. Publish the invalidation message
        // ourselves so subscribers on every other pod clear their local caches
        // too — otherwise they serve stale translations until the next
        // explicit cache:clear.
        $events->listen([Enabled::class, Disabled::class, Saved::class], $publishInvalidation);

        if ($pubSubConfig['enabled']) {
            $container->bind(DistributedCacheInvalidation::class, function ($container) use ($pubSubConfig) {
                return new DistributedCacheInvalidation(
                    $container->make(Factory::class),
                    $container->make(LocalCacheInvalidator::class),
                    $this->connection,
                    $pubSubConfig['version_key'],
                    $pubSubConfig['check_interval']
                );
            });

            // Insert right after the error handler — BEFORE AddAssetsRevisionHeader
            // (which rebuilds dirty assets and consumes the dirty flag) and before
            // anything that reads settings (session, locale): a stale pod must
            // clear its local state before any of that runs.
            foreach (['forum', 'admin', 'api'] as $frontend) {
                $container->extend("flarum.{$frontend}.middleware", function (array $middleware) use ($frontend) {
                    $position = array_search("flarum.{$frontend}.error_handler", $middleware, true);
                    $position = $position === false ? 0 : $position + 1;

                    array_splice($middleware, $position, 0, [DistributedCacheInvalidation::class]);

                    return $middleware;
                });
            }

            // Core's internal Api\Client replays the API middleware stack for
            // sub-requests made while rendering a page. Re-running the epoch
            // check there would add Redis GETs per inner call and could run a
            // full invalidation mid-render — the outer request already checked.
            $container->extend('flarum.api_client.exclude_middleware', function (array $exclude) {
                $exclude[] = DistributedCacheInvalidation::class;

                return $exclude;
            });
        }
    }

    private function normalizePubSubConfig(array $config, string $prefix = ''): array
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
            // Seconds between epoch checks in the request middleware (throttled
            // per pod via APCu when available). 0 (default) checks on every
            // request — one Redis GET, sub-millisecond.
            'check_interval' => max(0, (int) ($config['check_interval'] ?? 0)),
            // The epoch key honors the configured prefix, so two forums sharing
            // a Redis database don't invalidate each other.
            'version_key'    => $prefix.DistributedCacheInvalidation::VERSION_KEY,
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
        // /proc/$pid is readable regardless of process owner (Linux).
        // posix_kill($pid, 0) returns false for cross-user processes when
        // the caller lacks permission, causing false negatives.
        if (file_exists("/proc/$pid")) {
            return true;
        }

        // Fallback for non-Linux (macOS, BSD) where /proc is absent.
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return false;
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

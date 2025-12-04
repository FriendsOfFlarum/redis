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

namespace FoF\Redis\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Foundation\Paths;
use Flarum\Locale\LocaleManager;
use FoF\Redis\Service\DistributedCacheInvalidationService;
use Illuminate\Cache\Repository;
use Illuminate\Redis\Connections\Connection;
use Psr\Log\LoggerInterface;

class ListenDistributedCacheInvalidationCommand extends AbstractCommand
{
    /** @var bool */
    protected $stopRequested = false;

    /** @var bool */
    protected $asyncSignalDispatching = false;

    /** @var Connection|null */
    protected $activeSubscription = null;

    /** @var DistributedCacheInvalidationService */
    protected $service;

    /** @var Paths */
    protected $paths;

    /** @var LoggerInterface */
    protected $log;

    public function __construct(Paths $paths, LoggerInterface $log, ?string $name = null)
    {
        $this->paths = $paths;
        $this->log = $log;

        parent::__construct($name);
    }

    protected function configure()
    {
        $this
            ->setName('cache:listen-distributed-invalidation')
            ->setDescription('Listen for distributed cache invalidation events and clear the local cache accordingly');
    }

    protected function fire(): void
    {
        $this->service = resolve(DistributedCacheInvalidationService::class);
        $this->info('Waiting for distributed cache invalidation event with id: '.$this->service->getPodId().'...');
        $this->stopRequested = false;
        $this->registerSignalHandlers();

        while (!$this->stopRequested) {
            $this->dispatchPendingSignals();

            if ($this->stopRequested) {
                break;
            }

            try {
                $redis = $this->service->createSubscribeConnection();
                $this->activeSubscription = $redis;

                $this->reconcileCacheVersion();

                $redis->subscribe(['flarum.cache.cleared'], function (string $payload) {
                    $this->dispatchPendingSignals();

                    if ($this->stopRequested) {
                        throw new \RuntimeException('Shutdown requested, aborting payload processing.');
                    }

                    if (!$this->shouldProcessPayload($payload)) {
                        return;
                    }

                    $this->handleCacheClear();
                    $this->syncLastSeenVersion();
                });
            } catch (\Throwable $e) {
                $this->log->error('Redis listener stopped:', ['exception' => $e]);
                sleep(5); // backoff before retrying
            }
        }

        $this->info('Listener stopped.');
    }

    protected function registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            $this->asyncSignalDispatching = false;

            return;
        }

        $this->asyncSignalDispatching = function_exists('pcntl_async_signals')
            ? pcntl_async_signals(true)
            : false;

        pcntl_signal(SIGTERM, [$this, 'requestStop']);
        pcntl_signal(SIGINT, [$this, 'requestStop']);
    }

    protected function dispatchPendingSignals(): void
    {
        if (!$this->asyncSignalDispatching && function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    protected function reconcileCacheVersion(): void
    {
        try {
            $globalVersion = $this->service->getGlobalCacheVersion();

            if ($globalVersion === 0) {
                $this->log->critical($this->messagePrefix().'Global cache version is not set in Redis, skipping reconciliation.');

                return;
            }

            $lastSeenVersion = $this->service->getLastSeenVersion();

            if ($lastSeenVersion === 0) {
                $this->service->updateLastSeenVersion($globalVersion);

                return;
            }

            if ($globalVersion <= $lastSeenVersion) {
                return;
            }

            $this->log->warning($this->messagePrefix().'Detected out-of-date cache version (last seen: '.$lastSeenVersion.', global: '.$globalVersion.'), clearing cache...');
            $this->handleCacheClear();
            $this->service->updateLastSeenVersion($globalVersion);
        } catch (\Throwable $e) {
            $this->log->error($this->messagePrefix().'Cache reconciliation failed:', ['exception' => $e]);
        }
    }

    protected function messagePrefix()
    {
        return 'POD:'.$this->service->getPodId().' - ';
    }

    protected function handleCacheClear(): void
    {
        $this->info('Clearing local caches...');

        try {
            $locales = resolve(LocaleManager::class);

            // CRITICAL: Flush FileStore cache FIRST before deleting files
            // This prevents __PHP_Incomplete_Class__ errors with TextFormatter
            // The FileStore contains serialized formatter objects that reference
            // class files in storage/formatter/. We must clear the serialized cache
            // before deleting the class files, otherwise unserialization fails.
            (new Repository(resolve('cache.filestore')))->flush();

            // Clear file caches (suppress warnings if files don't exist)
            @array_map('unlink', glob($this->paths->storage.'/formatter/*') ?: []);
            @array_map('unlink', glob($this->paths->storage.'/locale/*') ?: []);
            @array_map('unlink', glob($this->paths->storage.'/views/*') ?: []);

            // Clear in-memory Symfony translator catalogues
            // This is crucial because Symfony caches translations in protected $catalogues array
            $locales->clearCache();
        } catch (\Exception $e) {
            // Fail gracefully - log but don't break the request
            $this->log->error($this->messagePrefix().'Failed to invalidate local caches:', ['exception' => $e]);
        }
    }

    protected function shouldProcessPayload(string $payload): bool
    {
        $this->info('Checking if payload should be processed...'.$payload);
        $timestamp = $this->extractTimestampFromPayload($payload);

        if ($timestamp === null) {
            return false;
        }

        return $timestamp > $this->service->getLastSeenVersion();
    }

    protected function extractTimestampFromPayload(string $payload): ?int
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded) || !isset($decoded['timestamp'])) {
            return null;
        }

        return (int) $decoded['timestamp'];
    }

    protected function syncLastSeenVersion(): void
    {
        $globalVersion = $this->service->getGlobalCacheVersion();

        if ($globalVersion > 0) {
            $this->service->updateLastSeenVersion($globalVersion);
        }
    }

    protected function requestStop(): void
    {
        $this->stopRequested = true;

        if ($this->activeSubscription === null) {
            return;
        }

        try {
            $this->activeSubscription->disconnect();
        } catch (\Throwable $e) {
            $this->log->debug($this->messagePrefix().'Failed to disconnect Redis during shutdown: '.$e->getMessage());
        }
    }
}

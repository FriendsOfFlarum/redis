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

namespace FoF\Redis\Cache;

use Flarum\Foundation\Paths;
use Flarum\Frontend\RecompileFrontendAssets;
use Flarum\Locale\LocaleManager;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * Applies a cache invalidation on this pod: clears the pod-local file caches
 * and flushes the compiled frontend assets, then records the applied epoch so
 * the same invalidation is not applied twice (the pub/sub subscriber and the
 * per-request middleware share this record).
 */
class LocalCacheInvalidator
{
    public function __construct(
        protected Container $container,
        protected Paths $paths,
        protected LocaleManager $locales
    ) {
    }

    public function invalidate(): void
    {
        // CRITICAL: Flush FileStore cache FIRST before deleting files
        // This prevents __PHP_Incomplete_Class__ errors with TextFormatter
        // The FileStore contains serialized formatter objects that reference
        // class files in storage/formatter/. We must clear the serialized cache
        // before deleting the class files, otherwise unserialization fails.
        (new Repository($this->container->make('cache.filestore')))->flush();

        // Clear file caches (suppress warnings if files don't exist)
        @array_map('unlink', glob($this->paths->storage.'/formatter/*') ?: []);
        @array_map('unlink', glob($this->paths->storage.'/locale/*') ?: []);
        @array_map('unlink', glob($this->paths->storage.'/views/*') ?: []);

        // Clear in-memory Symfony translator catalogues
        // This is crucial because Symfony caches translations in protected $catalogues array
        $this->locales->clearCache();

        // Drop the shared settings cache as well: a concurrent refill that read the DB
        // just before the invalidating write can re-store a pre-change snapshot AFTER
        // the writer's forget, and it would otherwise survive for the full TTL. Applying
        // an epoch forgets it again, so such a snapshot lives milliseconds, not an hour.
        if ($this->container->bound('cache.settings')) {
            try {
                $this->container->make('cache.settings')->forget('flarum:settings');
            } catch (\Exception $e) {
                // Settings cache unavailable — the local invalidation still proceeds.
            }
        }

        // Flush the compiled frontend assets too. They may have been rebuilt by a pod
        // whose locale catalogue was still stale (the assets live on shared storage but
        // are compiled from pod-local state); flushing them after clearing our local
        // caches means the next rebuild happens from fresh state.
        $this->flushCompiledAssets();

        // Clear OPcache if available. Only effective for the SAPI we run in (a CLI
        // subscriber cannot reset php-fpm's OPcache — the middleware path can).
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * The invalidation epoch this pod last applied (0 if none recorded).
     */
    public function appliedVersion(): int
    {
        $file = $this->epochFilePath();

        if (!file_exists($file)) {
            return 0;
        }

        return (int) @file_get_contents($file);
    }

    public function recordApplied(int $version): void
    {
        @file_put_contents($this->epochFilePath(), (string) $version);
    }

    /**
     * The epoch record lives in the base path (pod-local, like the subscriber
     * lock files) — storage/ may be a volume shared between pods.
     */
    public function epochFilePath(): string
    {
        return $this->paths->base.'/cache-epoch';
    }

    protected function flushCompiledAssets(): void
    {
        foreach (['forum', 'admin'] as $frontend) {
            try {
                (new RecompileFrontendAssets(
                    $this->container->make("flarum.assets.$frontend"),
                    $this->locales
                ))->flush();
            } catch (\Exception $e) {
                // A frontend may not be bound in some contexts; skip it.
            }
        }
    }
}

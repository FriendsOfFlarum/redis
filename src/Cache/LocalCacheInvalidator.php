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
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Applies a cache invalidation on this pod: clears the pod-local file caches,
 * drops the shared settings cache, and flushes the compiled frontend assets so
 * the next rebuild happens from fresh state. (Flushing is safe here on 1.x:
 * FileVersioner reads the revision manifest fresh from disk on every call, so
 * a long-running subscriber cannot rewrite it from a stale snapshot.).
 *
 * The applied epoch is recorded per pod AND per SAPI: the CLI subscriber and
 * php-fpm each keep their own record, because some work is only effective in
 * the SAPI that performs it (opcache_reset() in a CLI process cannot touch
 * php-fpm's OPcache). The invalidation is idempotent, so applying it once per
 * SAPI is safe and cheap.
 */
class LocalCacheInvalidator
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * @var Paths
     */
    protected $paths;

    /**
     * @var LocaleManager
     */
    protected $locales;

    public function __construct(Container $container, Paths $paths, LocaleManager $locales)
    {
        $this->container = $container;
        $this->paths = $paths;
        $this->locales = $locales;
    }

    public function invalidate(): void
    {
        // CRITICAL: Flush FileStore cache FIRST before deleting files
        // This prevents __PHP_Incomplete_Class__ errors with TextFormatter
        // The FileStore contains serialized formatter objects that reference
        // class files in storage/formatter/. We must clear the serialized cache
        // before deleting the class files, otherwise unserialization fails.
        (new Repository($this->container->make('cache.filestore')))->flush();

        // Clear file caches (suppress warnings if files don't exist).
        // storage/locale is handled by LocaleManager::clearCache() below.
        @array_map('unlink', glob($this->paths->storage.'/formatter/*') ?: []);
        @array_map('unlink', glob($this->paths->storage.'/views/*') ?: []);

        // Delete the compiled Symfony catalogue files. The catalogue cache file
        // names are not keyed by their source resources, so this is what forces
        // a rebuild from the (always-correct) YAML sources.
        $this->locales->clearCache();

        // Drop the shared settings cache as well: a concurrent refill that read the DB
        // just before the invalidating write can re-store a pre-change snapshot AFTER
        // the writer's forget, and it would otherwise survive for the full TTL. Applying
        // an epoch forgets it again, so such a snapshot lives milliseconds, not an hour.
        // Also forget the resolved repository instance so the remainder of THIS request
        // reads fresh values instead of the memory layer's warm pre-change snapshot.
        if ($this->container->bound('cache.settings')) {
            try {
                $this->container->make('cache.settings')->forget('flarum:settings');

                // Not on the Container contract, but present on the concrete
                // Illuminate container Flarum uses.
                if (method_exists($this->container, 'forgetInstance')) {
                    $this->container->forgetInstance(SettingsRepositoryInterface::class);
                }
            } catch (\Throwable $e) {
                // Settings cache unavailable — the local invalidation still proceeds.
            }
        }

        // Flush the compiled frontend assets too. They may have been rebuilt by a pod
        // whose locale catalogue was still stale (the assets live on shared storage but
        // are compiled from pod-local state); flushing them after clearing our local
        // caches means the next rebuild happens from fresh state.
        $this->flushCompiledAssets();

        // Clear OPcache if available. Only effective for the SAPI we run in (a CLI
        // subscriber cannot reset php-fpm's OPcache) — which is why the applied
        // epoch is recorded per SAPI: php-fpm performs its own apply.
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * The invalidation epoch this pod+SAPI last applied (0 if none recorded).
     */
    public function appliedVersion(): int
    {
        $file = $this->epochFilePath();

        if (!file_exists($file)) {
            return 0;
        }

        return (int) @file_get_contents($file);
    }

    /**
     * Record the applied epoch. Returns false when the record could not be
     * written (e.g. a read-only base path) — callers must not treat the epoch
     * as applied in that case, or the pod would re-invalidate forever.
     */
    public function recordApplied(int $version): bool
    {
        $written = @file_put_contents($this->epochFilePath(), (string) $version, LOCK_EX);

        if ($written === false) {
            $this->log('[Cache Invalidator] Cannot write the epoch record — is the base path writable?', [
                'file' => $this->epochFilePath(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Atomically claim the right to apply an epoch, so concurrent php-fpm
     * workers crossing the same request boundary don't all run the full
     * invalidation (N concurrent opcache resets). fopen('x') is the exclusive
     * primitive; a stale claim from a crashed worker is broken after 60s.
     */
    public function claimEpoch(int $version): bool
    {
        $claim = $this->claimFilePath();

        $handle = @fopen($claim, 'x');

        if ($handle === false) {
            if (file_exists($claim)) {
                // Another worker holds the claim. Break it only when stale.
                if (time() - (int) @filemtime($claim) <= 60) {
                    return false;
                }

                @unlink($claim);
                $handle = @fopen($claim, 'x');

                if ($handle === false) {
                    return false;
                }
            } else {
                // No file and no handle: the path is not writable. Fail open
                // (skip the apply) instead of looping a full invalidation on
                // every request.
                $this->log('[Cache Invalidator] Cannot create the epoch claim — is the base path writable?', [
                    'file' => $claim,
                ]);

                return false;
            }
        }

        fwrite($handle, (string) $version);
        fclose($handle);

        return true;
    }

    public function releaseClaim(): void
    {
        @unlink($this->claimFilePath());
    }

    /**
     * The epoch record lives in the base path (like the subscriber lock files),
     * suffixed by hostname AND SAPI: the hostname keeps records per pod even
     * when the install root is a volume shared between pods, and the SAPI
     * keeps the CLI subscriber's apply from suppressing php-fpm's (whose
     * opcache_reset is the only one that matters).
     */
    public function epochFilePath(): string
    {
        return $this->paths->base.'/cache-epoch-'.$this->podSuffix();
    }

    protected function claimFilePath(): string
    {
        return $this->epochFilePath().'.claim';
    }

    protected function podSuffix(): string
    {
        $host = gethostname() ?: 'pod';

        return preg_replace('/[^A-Za-z0-9_.-]/', '-', $host).'-'.PHP_SAPI;
    }

    protected function flushCompiledAssets(): void
    {
        foreach (['forum', 'admin'] as $frontend) {
            try {
                (new RecompileFrontendAssets(
                    $this->container->make("flarum.assets.$frontend"),
                    $this->locales
                ))->flush();
            } catch (\Throwable $e) {
                // A frontend may not be bound in some contexts; skip it.
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function log(string $message, array $context = []): void
    {
        try {
            $this->container->make(LoggerInterface::class)->warning($message, $context);
        } catch (\Throwable $e) {
            // Logging must never break the invalidation path.
        }
    }
}

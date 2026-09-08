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
use Flarum\Frontend\AssetManager;
use Flarum\Frontend\RecompileFrontendAssets;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Applies a cache invalidation on this pod: forgets the pod-local cache
 * entries, drops the shared settings cache, and marks every compiled asset set
 * dirty so core rebuilds it in place on the next request (see
 * RecompileFrontendAssets::markDirty() — nothing is deleted, so already-served
 * asset URLs keep resolving and the revision token doesn't flicker).
 *
 * The apply forgets cache entries rather than deleting the files those entries
 * reference. The only files it removes are the compiled locale catalogues,
 * whose names are not content-derived — deletion is the only way to force
 * their rebuild. That deletion has the same microsecond window (is_file() →
 * include) that core's own Extend\Locales::onEnable/onDisable has on the
 * acting pod.
 *
 * The applied epoch is recorded per pod AND per SAPI: the CLI subscriber and
 * php-fpm each keep their own record, so php-fpm performs its own apply even
 * when the subscriber already did (OPcache is per process pool: entries
 * dropped by the CLI process do not affect php-fpm's). The invalidation is
 * idempotent, so applying it once per SAPI is safe. Now that the apply no
 * longer resets OPcache globally, the second apply buys little — collapsing
 * the two is a possible follow-up.
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
        // Flush the file cache; its core tenant is `flarum.formatter`, the
        // serialized TextFormatter (core's own Formatter::flush() forgets just
        // that key). The formatter's generated renderer CLASS FILES in
        // storage/formatter/ are deliberately left in place, matching core's
        // runtime refresh: Formatter::flush() and the Formatter extender's
        // onEnable/onDisable only forget the cache entry, and `cache:clear` is
        // what sweeps the files. The renderer class is autoloaded from that
        // file DURING unserialize(); deleting it while another request is
        // unserializing the cached formatter — a microsecond window — leaves
        // that request with an incomplete object, and a method call on it
        // throws \Error, which core's render guard (catch Exception) does not
        // catch. Residual: the class name is a hash of the generated code, so
        // a rebuild after a config-neutral toggle rewrites the SAME file in
        // place, non-atomically, and every concurrent request that misses the
        // cache rebuilds it — OPcache normally serves the cached copy across
        // that window.
        (new Repository($this->container->make('cache.filestore')))->flush();

        // Compiled Blade views are left alone too. Core deletes them on the
        // acting pod when an extension that registers views is toggled
        // (Extend\View), but on other pods there is nothing to fix: compiled
        // files are keyed by the resolved source path and expire by source
        // mtime, so they can only be stale if a template SOURCE changed —
        // which toggles and settings saves never do (deployments do, and
        // those run cache:clear).

        // The compiled locale catalogues are the exception: their filenames are
        // not derived from their contents, so nothing detects staleness and
        // deletion is the only way to force a rebuild from the YAML sources.
        //
        // markAssetsDirty() below calls markDirty(), which ALSO calls
        // LocaleManager::clearCache() — so the deletion happens twice. Keep
        // this call: it must run before the OPcache entries are invalidated
        // (which needs the file list captured pre-deletion), and it keeps the
        // apply correct even if a future core release stops clearing
        // catalogues from markDirty() or markAssetsDirty() fails and is
        // swallowed. The second deletion is a cheap no-op on an empty dir.
        $this->clearLocaleCatalogues();

        // Drop the shared settings cache as well: a concurrent refill that read the DB
        // just before the invalidating write can re-store a pre-change snapshot AFTER
        // the writer's forget, and it would otherwise survive for the full TTL. Applying
        // an epoch forgets it again, so such a snapshot lives milliseconds, not an hour.
        // Also forget the resolved repository instance so the remainder of THIS request
        // reads fresh values instead of the memory layer's warm pre-change snapshot.
        if ($this->container->bound('cache.settings')) {
            try {
                $this->container->make('cache.settings')->forget('flarum:settings');
                $this->container->forgetInstance(SettingsRepositoryInterface::class);
            } catch (\Throwable $e) {
                // Settings cache unavailable — the local invalidation still proceeds.
            }
        }

        // Mark every compiled asset set (forum, admin, common, and any custom
        // frontend) dirty. Core's AddAssetsRevisionHeader middleware rebuilds
        // dirty sets in place early in the next freshly-booted request — after
        // our middleware has cleared this pod's local state, so the rebuild
        // can no longer bake stale catalogues into the shared assets. We never
        // touch the compiled files or the revision manifest ourselves: doing
        // so from the long-running subscriber would rewrite the shared
        // manifest from a boot-time snapshot (FileVersioner caches it per
        // instance) and silently revert every revision recorded since.
        $this->markAssetsDirty();
    }

    /**
     * Delete the compiled locale catalogues and invalidate the OPcache entry of
     * every PHP file among them in this SAPI.
     *
     * This is why an apply is needed at all on a pod that did not perform the
     * admin action. Symfony names a compiled catalogue
     * `catalogue.<locale>.<hash>.php`, where the hash covers only
     * `fallback_locales` — NOT the translated content — and
     * ConfigCache::isFresh() short-circuits to `is_file()` whenever debug is
     * off, which is every production install. So a catalogue that predates a
     * newly-enabled extension is considered fresh forever, and deleting the
     * file is the only thing that forces a rebuild from the YAML sources.
     *
     * The file list is captured BEFORE clearCache() unlinks it, and is globbed
     * as broadly as clearCache() itself (which deletes `/*`) so that a
     * `.php.meta` sibling or any future compiled artefact is covered too;
     * opcache_invalidate() is only meaningful for the PHP files, so non-PHP
     * entries are filtered out rather than glob-restricted, which would have
     * silently narrowed what we invalidate if Symfony changed its naming.
     *
     * The invalidation is belt-and-braces: Symfony re-invalidates a catalogue
     * when it rewrites it, and from the CLI subscriber the call is a no-op
     * (opcache.enable_cli is off by default). It replaces the previous global
     * opcache_reset(), which forced the whole php-fpm pool to recompile
     * everything mid-traffic — far more disruptive than the staleness it
     * guarded against.
     */
    protected function clearLocaleCatalogues(): void
    {
        $files = array_filter(
            glob($this->paths->storage.'/locale/*') ?: [],
            fn (string $file) => str_ends_with($file, '.php')
        );

        $this->locales->clearCache();

        if (!function_exists('opcache_invalidate')) {
            return;
        }

        foreach ($files as $file) {
            @opcache_invalidate($file, true);
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
     * invalidation at once. fopen('x') is the exclusive primitive; a stale
     * claim from a crashed worker is broken after 60s.
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
     * keeps the CLI subscriber's apply from suppressing php-fpm's (OPcache is
     * per process pool, so only php-fpm's own apply reaches its OPcache).
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

    protected function markAssetsDirty(): void
    {
        try {
            /** @var AssetManager $manager */
            $manager = $this->container->make(AssetManager::class);
            $settings = $this->container->make(SettingsRepositoryInterface::class);
            $events = $this->container->make(Dispatcher::class);

            foreach ($manager->all() as $assets) {
                (new RecompileFrontendAssets($assets, $this->locales, $events, $settings))->markDirty();
            }
        } catch (\Throwable $e) {
            $this->log('[Cache Invalidator] Failed to mark compiled assets dirty', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    protected function log(string $message, array $context = []): void
    {
        try {
            $this->container->make(LoggerInterface::class)->warning($message, $context);
        } catch (\Throwable $e) {
            // Logging must never break the invalidation path.
        }
    }
}

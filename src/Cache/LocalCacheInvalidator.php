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
use Flarum\Locale\LocaleManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

/**
 * Applies a cache invalidation on this pod.
 *
 * The apply is deliberately NON-DESTRUCTIVE to anything a concurrent request
 * may be using: it forgets cache entries (pointers) rather than deleting the
 * files those entries reference, and never touches the shared compiled assets.
 * The only files it removes are the compiled locale catalogues, whose names are
 * not content-derived — deletion is the only way to force their rebuild.
 *
 * The applied epoch is recorded per pod AND per SAPI: the CLI subscriber and
 * php-fpm each keep their own record, because some work is only effective in
 * the SAPI that performs it (OPcache invalidation from the CLI subscriber cannot
 * touch php-fpm's OPcache). The invalidation is idempotent, so applying it once
 * per SAPI is safe and cheap.
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
        // Forget the file-cache entries (among them `flarum.formatter`, the
        // serialized TextFormatter). The formatter's generated renderer CLASS
        // FILES in storage/formatter/ are deliberately left in place: their
        // names are content hashes, so a rebuilt formatter writes new files and
        // the superseded ones are inert. Deleting them mid-traffic breaks
        // requests that are unserializing the cached formatter — the class file
        // vanishes, the object comes back incomplete, and calling a method on it
        // throws \Error (not \Exception, so it escapes core's render guard and
        // becomes a 500). Core's own runtime refresh, Formatter::flush(), also
        // only forgets the cache entry; `cache:clear` sweeps the orphans.
        (new Repository($this->container->make('cache.filestore')))->flush();

        // Compiled Blade views are left alone for the same reason: Blade
        // recompiles by source mtime, and toggles or settings saves never change
        // template sources (deployments do, and those run cache:clear).

        // The compiled locale catalogues are the exception: their filenames are
        // not derived from their contents, so nothing detects staleness and
        // deletion is the only way to force a rebuild from the YAML sources.
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

                // Not on the Container contract, but present on the concrete
                // Illuminate container Flarum uses.
                if (method_exists($this->container, 'forgetInstance')) {
                    $this->container->forgetInstance(SettingsRepositoryInterface::class);
                }
            } catch (\Throwable $e) {
                // Settings cache unavailable — the local invalidation still proceeds.
            }
        }

        // NOTE: the shared compiled frontend assets are deliberately NOT touched.
        // Core flushes them itself, once, on the pod that handles the admin
        // action. Flushing again from every pod's apply meant up to 2N actors
        // per toggle rewriting rev-manifest.json, whose updates are non-atomic
        // read-modify-writes: interleavings lose updates, leaving a revision
        // pointing at stale content that browsers and CDNs then cache until the
        // next admin action.
    }

    /**
     * Delete the compiled locale catalogues and drop their OPcache entries.
     *
     * Targeted invalidation rather than opcache_reset(): a global reset
     * restarts the whole php-fpm pool mid-traffic (recompile storm, and
     * lazily-autoloaded classes can fail during the swap), which is far more
     * disruptive than the staleness it guards against.
     */
    protected function clearLocaleCatalogues(): void
    {
        $files = glob($this->paths->storage.'/locale/*.php') ?: [];

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
     * invalidation at once). fopen('x') is the exclusive
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

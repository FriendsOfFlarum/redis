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
 * The apply forgets cache entries rather than deleting the files those entries
 * reference, and never touches the shared compiled assets. The only files it
 * removes are the compiled locale catalogues, whose names are not
 * content-derived — deletion is the only way to force their rebuild. That
 * deletion has the same microsecond window (is_file() → include) that core's
 * own Extend\Locales::onEnable/onDisable has on the acting pod.
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
        // Flush the file cache. On 1.x its only core tenant is `flarum.formatter`,
        // the serialized TextFormatter (core's own Formatter::flush() forgets
        // just that key). The formatter's generated renderer CLASS FILES in
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
     * Delete the compiled locale catalogues and invalidate their OPcache
     * entries in this SAPI.
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

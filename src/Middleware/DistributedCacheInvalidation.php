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

namespace FoF\Redis\Middleware;

use Flarum\Foundation\Paths;
use Flarum\Locale\LocaleManager;
use Illuminate\Contracts\Redis\Factory as Redis;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware to handle distributed cache invalidation across multiple instances.
 *
 * When cache is cleared on one instance, this middleware detects the change
 * and invalidates local file caches on other instances.
 */
class DistributedCacheInvalidation implements MiddlewareInterface
{
    /**
     * Timestamp of last check (per PHP-FPM worker).
     *
     * @var int
     */
    private static $lastCheck = 0;

    /**
     * How often to check Redis for cache version changes (seconds).
     *
     * @var int
     */
    private const DEFAULT_CHECK_INTERVAL = 5;

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // Throttle: Only check Redis periodically per PHP-FPM worker
        $interval = $this->getCheckInterval();
        $now = time();

        if ($now - self::$lastCheck >= $interval) {
            self::$lastCheck = $now;

            if ($this->shouldInvalidateLocal()) {
                $this->invalidateLocalCaches();
            }
        }

        return $handler->handle($request);
    }

    /**
     * Check if local caches should be invalidated based on global Redis version.
     */
    private function shouldInvalidateLocal(): bool
    {
        try {
            $redis = resolve(Redis::class);
            $globalVersion = (int) $redis->connection('fof.cache')
                ->get('flarum:cache:version');

            // If no version set yet, nothing to invalidate
            if ($globalVersion === 0) {
                return false;
            }

            // Use static variable to track last seen version per PHP-FPM worker
            // This persists across requests within the same worker process
            static $lastGlobalVersion = 0;

            if ($globalVersion > $lastGlobalVersion) {
                $lastGlobalVersion = $globalVersion;

                return true;
            }

            return false;
        } catch (\Exception $e) {
            // Redis connection failed - fail gracefully
            // Don't invalidate to avoid unnecessary file operations
            return false;
        }
    }

    /**
     * Invalidate local file caches and in-memory translator catalogues.
     */
    private function invalidateLocalCaches(): void
    {
        try {
            $paths = resolve(Paths::class);
            $locales = resolve(LocaleManager::class);

            // Clear file caches (suppress warnings if files don't exist)
            @array_map('unlink', glob($paths->storage.'/formatter/*') ?: []);
            @array_map('unlink', glob($paths->storage.'/locale/*') ?: []);
            @array_map('unlink', glob($paths->storage.'/views/*') ?: []);

            // Clear in-memory Symfony translator catalogues
            // This is crucial because Symfony caches translations in protected $catalogues array
            $locales->clearCache();

            // Update local version to match global
            $this->updateLocalVersion();
        } catch (\Exception $e) {
            // Fail gracefully - log but don't break the request
            // In production, you might want to log this
        }
    }

    /**
     * Update the local version tracker to match global version.
     *
     * Note: Version is tracked via static variable in shouldInvalidateLocal(),
     * so this method doesn't need to do anything. Kept for potential future use.
     */
    private function updateLocalVersion(): void
    {
        // No-op: static variable in shouldInvalidateLocal() already updated
    }

    /**
     * Get the check interval from config.
     */
    private function getCheckInterval(): int
    {
        // Allow configuration via config.php
        $config = resolve('flarum.config');

        return $config['cache']['distributed_invalidation_interval']
            ?? self::DEFAULT_CHECK_INTERVAL;
    }
}

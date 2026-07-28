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

namespace FoF\Redis\Settings;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class RedisCacheSettingsRepository implements SettingsRepositoryInterface
{
    protected SettingsRepositoryInterface $inner;
    protected CacheRepository $cache;
    protected string $cacheKey = 'flarum:settings';
    protected int $ttl = 3600; // 1 hour
    protected bool $loading = false;

    /**
     * The companion key `flexible()` writes alongside the value to record when
     * the cache was last filled. Reads use it to decide staleness, so any
     * in-place patch of the value must refresh it too, or the just-written
     * value is judged stale and a background refresh clobbers it from the DB.
     * Mirrors Illuminate\Cache\Repository::FLEXIBLE_CREATED_KEY_PREFIX.
     */
    protected string $createdKey = 'illuminate:cache:flexible:created:flarum:settings';

    public function __construct(SettingsRepositoryInterface $inner, CacheRepository $cache)
    {
        $this->inner = $inner;
        $this->cache = $cache;
    }

    public function all(): array
    {
        // Guard against re-entrant calls triggered by DB events fired during
        // the cache-fill query (e.g. a subscriber that reads settings on
        // StatementPrepared). Without this, the cache miss causes a DB query
        // which fires an event which calls all() again → infinite recursion.
        if ($this->loading) {
            return [];
        }

        $this->loading = true;

        try {
            return $this->cache->flexible($this->cacheKey, [$this->ttl - 300, $this->ttl], function () {
                return $this->inner->all();
            });
        } finally {
            $this->loading = false;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return Arr::get($all, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->inner->set($key, $value);

        // Patch the cached array in place so a warm cache reflects the new value
        // without re-reading the database — which, on a read/write split with no
        // `sticky` connection, could otherwise re-populate a stale value from a
        // lagging replica. If the cache is cold there is nothing to patch; the
        // next all() rebuilds it from the database on its own.
        $all = $this->cache->get($this->cacheKey);

        if (is_array($all)) {
            $all[$key] = $value;
            $this->writeCache($all);
        }
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);

        // Remove the key from the cached array rather than dropping the whole entry,
        // for the same reason as set(): avoids a stale replica re-read.
        // If the cache is cold, there is nothing to patch.
        $all = $this->cache->get($this->cacheKey);

        if (is_array($all)) {
            unset($all[$key]);
            $this->writeCache($all);
        }
    }

    /**
     * Write the patched settings array back to the cache, keeping the
     * `flexible()` created-timestamp in sync so the just-written value is
     * treated as fresh rather than immediately stale (which would trigger a
     * background DB refresh that could clobber it from a lagging replica).
     */
    protected function writeCache(array $all): void
    {
        $this->cache->put($this->cacheKey, $all, $this->ttl);
        $this->cache->put($this->createdKey, Carbon::now()->getTimestamp(), $this->ttl);
    }
}

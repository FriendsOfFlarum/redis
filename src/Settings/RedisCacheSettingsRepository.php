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

class RedisCacheSettingsRepository implements SettingsRepositoryInterface
{
    protected SettingsRepositoryInterface $inner;
    protected CacheRepository $cache;
    protected string $cacheKey = 'flarum:settings';
    protected int $ttl = 3600; // 1 hour
    protected bool $loading = false;

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
            return $this->cache->remember($this->cacheKey, $this->ttl, function () {
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

        // Update the cache in-place to avoid a stale read-replica re-populating it.
        // Environments with a read/write DB split (no `sticky` connection) would
        // otherwise re-read the old value from the replica after forget().
        // If the cache is cold, drop it entirely so the next all() re-builds from DB.
        $all = $this->cache->get($this->cacheKey);

        if (is_array($all)) {
            $all[$key] = $value;
            $this->cache->put($this->cacheKey, $all, $this->ttl);
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
            $this->cache->put($this->cacheKey, $all, $this->ttl);
        }
    }
}

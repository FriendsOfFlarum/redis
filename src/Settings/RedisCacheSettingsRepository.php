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
    protected $inner;
    protected $cache;
    protected $cacheKey = 'flarum:settings';
    protected $ttl = 3600; // 1 hour
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

    public function get($key, $default = null)
    {
        $all = $this->all();

        return Arr::get($all, $key, $default);
    }

    public function set($key, $value)
    {
        $this->inner->set($key, $value);

        // Invalidate entire cache to prevent race conditions in multi-container environments
        // The cache will be rebuilt from database on next read
        $this->cache->forget($this->cacheKey);
    }

    public function delete($key)
    {
        $this->inner->delete($key);

        // Invalidate entire cache to prevent race conditions in multi-container environments
        // The cache will be rebuilt from database on next read
        $this->cache->forget($this->cacheKey);
    }
}

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

    public function __construct(SettingsRepositoryInterface $inner, CacheRepository $cache)
    {
        $this->inner = $inner;
        $this->cache = $cache;
    }

    public function all(): array
    {
        return $this->cache->remember($this->cacheKey, $this->ttl, function () {
            return $this->inner->all();
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return Arr::get($all, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->inner->set($key, $value);

        // Invalidate entire cache to prevent race conditions in multi-container environments
        // The cache will be rebuilt from database on next read
        $this->cache->forget($this->cacheKey);
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);

        // Invalidate entire cache to prevent race conditions in multi-container environments
        // The cache will be rebuilt from database on next read
        $this->cache->forget($this->cacheKey);
    }
}

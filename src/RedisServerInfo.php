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

namespace FoF\Redis;

use FoF\Redis\Overrides\RedisManager;
use Illuminate\Support\Arr;

/**
 * Fetches and exposes typed metadata about the connected Redis/Valkey server.
 *
 * Inject this class directly; it is bound as a singleton by fof/redis.
 */
class RedisServerInfo
{
    private ?array $serverSection = null;
    private ?string $error = null;

    public function __construct(
        private readonly RedisManager $redis,
        private readonly string $connection = 'fof.cache'
    ) {
    }

    /**
     * The server software name: 'Redis' or 'Valkey'.
     */
    public function serverName(): string
    {
        return $this->isValkey() ? 'Valkey' : 'Redis';
    }

    /**
     * The server version string, e.g. '7.2.4'.
     */
    public function version(): string
    {
        $section = $this->section();

        if ($this->isValkey()) {
            return Arr::get($section, 'valkey_version', 'unknown');
        }

        return Arr::get($section, 'redis_version', 'unknown');
    }

    /**
     * The server mode, e.g. 'standalone', 'cluster', 'sentinel'.
     */
    public function mode(): string
    {
        return Arr::get($this->section(), 'redis_mode', 'standalone');
    }

    /**
     * Whether the server software is Valkey rather than Redis.
     */
    public function isValkey(): bool
    {
        return Arr::has($this->section(), 'valkey_version');
    }

    /**
     * Whether the INFO fetch failed.
     */
    public function hasError(): bool
    {
        $this->section();

        return $this->error !== null;
    }

    /**
     * The error message if the INFO fetch failed, or null.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * The raw parsed Server section from INFO, or an empty array on error.
     */
    public function section(): array
    {
        if ($this->serverSection !== null || $this->error !== null) {
            return $this->serverSection ?? [];
        }

        try {
            $info = $this->redis->connection($this->connection)->info('server');
            // Predis nests the section under a 'Server' key; phpredis returns flat.
            $this->serverSection = $info['Server'] ?? $info;
        } catch (\Exception $e) {
            $this->error = $e->getMessage();
            $this->serverSection = [];
        }

        return $this->serverSection;
    }
}

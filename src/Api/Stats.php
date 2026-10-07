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

namespace FoF\Redis\Api;

use FoF\Redis\Overrides\RedisManager;
use FoF\Redis\Traits\RetrievesRedisInfo;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Server metrics for the admin dashboard's Redis widget.
 *
 * Registered on the admin route collection, whose middleware stack ends in
 * RequireAdministrateAbility — so no per-handler authorization is needed here.
 */
class Stats implements RequestHandlerInterface
{
    use RetrievesRedisInfo;

    public function __construct(
        protected RedisManager $redis
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $info = $this->getInfo();

        if (Arr::has($info, 'error')) {
            return new JsonResponse([
                'error' => Arr::get($info, 'error'),
            ], 500);
        }

        $memoryUsedBytes = Arr::get($info, 'Memory.used_memory', 0);
        $memoryMaxBytes = Arr::get($info, 'Memory.maxmemory', 0);
        $memoryPercentage = $memoryMaxBytes > 0
            ? round(($memoryUsedBytes / $memoryMaxBytes) * 100, 2)
            : null;

        return new JsonResponse([
            'redis' => [
                'memory_used'       => Arr::get($info, 'Memory.used_memory_human', '0'),
                'memory_used_bytes' => $memoryUsedBytes,
                'memory_peak'       => Arr::get($info, 'Memory.used_memory_peak_human', '0'),
                'memory_max'        => $this->formatMaxMemory(Arr::get($info, 'Memory.maxmemory_human', '0')),
                'memory_max_bytes'  => $memoryMaxBytes,
                'memory_percentage' => $memoryPercentage,
                'eviction_policy'   => Arr::get($info, 'Memory.maxmemory_policy', ''),
                'ops_per_sec'       => Arr::get($info, 'Stats.instantaneous_ops_per_sec', 0),
                'connected_clients' => Arr::get($info, 'Clients.connected_clients', 0),
                'blocked_clients'   => Arr::get($info, 'Clients.blocked_clients', 0),
            ],
            'timestamp' => time(),
        ]);
    }

    /**
     * A maxmemory of 0 means unbounded, which reads better as "auto" than "0B".
     */
    private function formatMaxMemory(string $maxMemory): string
    {
        if ($maxMemory === '0' || $maxMemory === '0B') {
            return 'auto';
        }

        return $maxMemory;
    }
}

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

use FoF\Redis\Cache\LocalCacheInvalidator;
use Illuminate\Contracts\Redis\Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Synchronous backstop for the pub/sub invalidation: before serving a request,
 * compare the shared invalidation epoch in Redis with the epoch this pod last
 * applied, and clear the pod-local caches when behind.
 *
 * Pub/sub delivery is asynchronous and fire-and-forget: a request racing the
 * message can rebuild shared artifacts from stale local state, and a message
 * published while this pod's subscriber was down is lost forever. The epoch is
 * durable state, checked before the request is handled, so both cases self-heal.
 */
class DistributedCacheInvalidation implements MiddlewareInterface
{
    public const VERSION_KEY = 'flarum:cache:version';

    /**
     * Timestamp of the last Redis check (per PHP-FPM worker).
     *
     * @var int
     */
    protected static $lastCheck = 0;

    /**
     * @var Factory
     */
    protected $redis;

    /**
     * @var LocalCacheInvalidator
     */
    protected $invalidator;

    /**
     * @var string
     */
    protected $connection;

    /**
     * @var int
     */
    protected $checkInterval;

    public function __construct(Factory $redis, LocalCacheInvalidator $invalidator, string $connection, int $checkInterval = 0)
    {
        $this->redis = $redis;
        $this->invalidator = $invalidator;
        $this->connection = $connection;
        $this->checkInterval = $checkInterval;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $now = time();

        if ($this->checkInterval === 0 || $now - self::$lastCheck >= $this->checkInterval) {
            self::$lastCheck = $now;

            $this->applyPendingInvalidation();
        }

        return $handler->handle($request);
    }

    protected function applyPendingInvalidation(): void
    {
        try {
            $globalVersion = (int) $this->redis->connection($this->connection)->get(self::VERSION_KEY);
        } catch (\Exception $e) {
            // Redis unavailable — fail open, serve the request.
            return;
        }

        if ($globalVersion === 0) {
            return;
        }

        $appliedVersion = $this->invalidator->appliedVersion();

        // First sight of an epoch on this pod (fresh pod, no record yet): its
        // caches are new, adopt the epoch without clearing anything.
        if ($appliedVersion === 0) {
            $this->invalidator->recordApplied($globalVersion);

            return;
        }

        if ($globalVersion > $appliedVersion) {
            try {
                $this->invalidator->invalidate();
                $this->invalidator->recordApplied($globalVersion);
            } catch (\Exception $e) {
                // Fail gracefully — leave the record unwritten so the next
                // request retries the invalidation.
            }
        }
    }
}

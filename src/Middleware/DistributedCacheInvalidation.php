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
 *
 * This middleware is inserted right after each stack's error handler, before
 * the session/locale middleware that read settings: a stale pod must clear its
 * local state before any of that runs.
 */
class DistributedCacheInvalidation implements MiddlewareInterface
{
    const VERSION_KEY = 'flarum:cache:version';

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
     * @var string
     */
    protected $versionKey;

    /**
     * @var int
     */
    protected $checkInterval;

    public function __construct(
        Factory $redis,
        LocalCacheInvalidator $invalidator,
        string $connection,
        string $versionKey = self::VERSION_KEY,
        int $checkInterval = 0
    ) {
        $this->redis = $redis;
        $this->invalidator = $invalidator;
        $this->connection = $connection;
        $this->versionKey = $versionKey;
        $this->checkInterval = $checkInterval;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldCheck()) {
            $this->applyPendingInvalidation();
        }

        return $handler->handle($request);
    }

    /**
     * Throttle the Redis check to at most one per check_interval seconds per
     * pod. PHP statics don't survive between php-fpm requests, so the throttle
     * is backed by APCu (shared across a pod's workers); without APCu — or
     * with the default interval of 0 — the epoch is checked on every request
     * (one Redis GET, sub-millisecond).
     */
    protected function shouldCheck(): bool
    {
        if ($this->checkInterval <= 0 || !function_exists('apcu_add') || !apcu_enabled()) {
            return true;
        }

        // apcu_add is atomic: it returns true only for the one caller that
        // created the entry; everyone else skips until the TTL expires.
        return apcu_add('fof.redis.epoch_checked', 1, $this->checkInterval);
    }

    protected function applyPendingInvalidation(): void
    {
        try {
            $globalVersion = (int) $this->redis->connection($this->connection)->get($this->versionKey);
        } catch (\Throwable $e) {
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

        if ($globalVersion <= $appliedVersion) {
            return;
        }

        // Claim the apply so concurrent workers crossing the same request
        // boundary don't all run the full invalidation. Losing the claim is
        // fine — the winner does the work, and an unwritable base path fails
        // open (logged by the invalidator) instead of looping.
        if (!$this->invalidator->claimEpoch($globalVersion)) {
            return;
        }

        try {
            $this->invalidator->invalidate();
            $this->invalidator->recordApplied($globalVersion);
        } catch (\Throwable $e) {
            // Fail gracefully — the record stays unwritten, so a later
            // request retries the invalidation.
        } finally {
            $this->invalidator->releaseClaim();
        }
    }
}

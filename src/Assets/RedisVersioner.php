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

namespace FoF\Redis\Assets;

use Flarum\Frontend\Compiler\VersionerInterface;
use Illuminate\Contracts\Redis\Factory;

/**
 * Stores compiled-asset revisions in a Redis hash instead of rev-manifest.json.
 *
 * Core's FileVersioner updates the manifest with a read-modify-write of the
 * whole file: read the JSON, change one key, write the JSON back. Several
 * actors do that concurrently after an admin action — core's flush on the
 * acting pod is a dozen or so sequential writes, and every pod's first render
 * after the flush commits its own — so interleavings lose updates. Because
 * RevisionCompiler::getUrl() only commits when a revision is MISSING, a lost
 * update leaves a stale revision in place until the next admin action, and
 * browsers and CDNs keep the stale URL. Symptoms: an extension's settings page
 * empty right after enabling it, a disable not reflected on the forum, mixed
 * old/new locale assets.
 *
 * A Redis hash makes every write a single-field atomic operation (HSET/HDEL),
 * so concurrent writers cannot clobber each other. The lazy
 * rebuild-by-whoever-comes-next is unchanged — that is core's design.
 *
 * Reads go to Redis on every call, exactly like core's 1.x FileVersioner reads
 * the manifest file on every call (a handful of sub-millisecond HGETs per page
 * render). No memo, so a long-lived process such as the cache:subscribe
 * command can never hold a stale view.
 *
 * Errors propagate, exactly like FileVersioner's storage errors. That is
 * deliberate: a read failure reported as "no revision" would make
 * RevisionCompiler::getUrl() recompile on every request and then return null,
 * rendering pages without any CSS or JS; a write failure reported as success
 * would leave a stale revision in the hash with nothing to trigger a retry.
 * A Redis error here surfaces as an error page instead, the same way it does
 * for the cache, settings and session stores this extension puts on Redis.
 */
class RedisVersioner implements VersionerInterface
{
    const KEY = 'flarum:assets:revisions';

    /**
     * @var Factory
     */
    protected $redis;

    /**
     * @var string
     */
    protected $connection;

    /**
     * @var string
     */
    protected $key;

    public function __construct(Factory $redis, string $connection, string $key = self::KEY)
    {
        $this->redis = $redis;
        $this->connection = $connection;
        $this->key = $key;
    }

    public function putRevision(string $file, ?string $revision)
    {
        if ($revision) {
            $this->redis->connection($this->connection)->hset($this->key, $file, $revision);
        } else {
            $this->redis->connection($this->connection)->hdel($this->key, $file);
        }
    }

    public function getRevision(string $file): ?string
    {
        // phpredis returns false for a missing field, Predis returns null.
        $revision = $this->redis->connection($this->connection)->hget($this->key, $file);

        return is_string($revision) && $revision !== '' ? $revision : null;
    }

    /**
     * Every recorded revision, keyed by asset file name.
     *
     * Not part of the 1.x VersionerInterface; provided for diagnostics and tests.
     *
     * @return array<string, string>
     */
    public function allRevisions(): array
    {
        $revisions = [];

        foreach ((array) $this->redis->connection($this->connection)->hgetall($this->key) as $file => $revision) {
            if (is_string($revision) && $revision !== '') {
                $revisions[(string) $file] = $revision;
            }
        }

        return $revisions;
    }
}

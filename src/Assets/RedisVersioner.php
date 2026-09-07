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
 * whole file: read the JSON, change one key, write the JSON back. On 2.x the
 * writers race each other after an admin action: core marks every asset set
 * dirty, and the next page or API request on EACH pod rebuilds it in place
 * (RecompileFrontendAssets::recompileIfDirty()) — several pods commit at once,
 * and interleaved read-modify-writes lose updates. Because
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
 * Reads are memoised per instance, exactly like core's FileVersioner (one
 * HGETALL per request for the singleton); writes update the memo.
 *
 * Errors propagate, exactly like FileVersioner's storage errors. That is
 * deliberate: RecompileFrontendAssets::recompileIfDirty() clears the shared
 * dirty flag right after a successful rebuild, so a write failure reported as
 * success would consume the flag while the hash still holds the OLD revision —
 * stale content served until the next admin action, the very bug this class
 * exists to remove. And a read failure reported as "no revision" would make
 * RevisionCompiler::getUrl() recompile on every request and then return null,
 * rendering pages without any CSS or JS. A Redis error here surfaces as an
 * error page instead, the same way it does for the cache, settings and
 * session stores this extension puts on Redis.
 */
class RedisVersioner implements VersionerInterface
{
    public const string KEY = 'flarum:assets:revisions';

    /**
     * @var array<string, string>|null
     */
    protected ?array $revisions = null;

    public function __construct(
        protected Factory $redis,
        protected string $connection,
        protected string $key = self::KEY
    ) {
    }

    public function putRevision(string $file, ?string $revision): void
    {
        if ($revision) {
            $this->redis->connection($this->connection)->hset($this->key, $file, $revision);
        } else {
            $this->redis->connection($this->connection)->hdel($this->key, $file);
        }

        if ($this->revisions === null) {
            return;
        }

        if ($revision) {
            $this->revisions[$file] = $revision;
        } else {
            unset($this->revisions[$file]);
        }
    }

    public function getRevision(string $file): ?string
    {
        return $this->allRevisions()[$file] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function allRevisions(): array
    {
        if ($this->revisions !== null) {
            return $this->revisions;
        }

        $revisions = [];

        // phpredis and Predis both return an associative array here; values
        // are strings, but guard against anything else a misconfigured key
        // could yield rather than hand core a non-string revision.
        foreach ((array) $this->redis->connection($this->connection)->hgetall($this->key) as $file => $revision) {
            if (is_string($revision) && $revision !== '') {
                $revisions[(string) $file] = $revision;
            }
        }

        return $this->revisions = $revisions;
    }
}

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

namespace FoF\Redis\Tests\unit;

use FoF\Redis\Assets\RedisVersioner;
use Illuminate\Contracts\Redis\Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RedisVersionerTest extends TestCase
{
    #[Test]
    public function it_stores_reads_and_removes_revisions()
    {
        $versioner = $this->versioner($this->hash());

        $this->assertNull($versioner->getRevision('forum.js'));

        $versioner->putRevision('forum.js', 'abc123');
        $this->assertSame('abc123', $versioner->getRevision('forum.js'));

        $versioner->putRevision('forum.js', null);
        $this->assertNull($versioner->getRevision('forum.js'));
        $this->assertSame([], $versioner->allRevisions());
    }

    #[Test]
    public function writes_keep_the_memoised_read_in_sync()
    {
        $versioner = $this->versioner($this->hash());

        // Prime the memo.
        $this->assertSame([], $versioner->allRevisions());

        $versioner->putRevision('forum.js', 'v1');
        $versioner->putRevision('admin.js', 'v2');
        $this->assertSame(['forum.js' => 'v1', 'admin.js' => 'v2'], $versioner->allRevisions());

        $versioner->putRevision('forum.js', null);
        $this->assertSame(['admin.js' => 'v2'], $versioner->allRevisions());
    }

    #[Test]
    public function the_write_path_only_ever_issues_single_field_commands()
    {
        // Atomicity comes from the command choice: a per-field HSET/HDEL cannot
        // lose a concurrent writer's update, whereas anything that reads the
        // whole hash and writes it back (HGETALL + HMSET/DEL) can. Pin the
        // commands the write path uses, so a refactor towards read-modify-write
        // fails this test.
        $hash = $this->hash();
        $versioner = $this->versioner($hash);

        $versioner->allRevisions(); // prime the memo — writes must not need a read afterwards
        $hash->log = [];

        $versioner->putRevision('forum.js', 'v1');
        $versioner->putRevision('admin.js', null);

        $this->assertSame(['hset', 'hdel'], array_column($hash->log, 0));
    }

    #[Test]
    public function two_instances_write_independent_fields_and_a_fresh_reader_sees_both()
    {
        $hash = $this->hash();
        $a = $this->versioner($hash);
        $b = $this->versioner($hash);

        $a->allRevisions();
        $b->allRevisions();

        $a->putRevision('forum.js', 'from-a');
        $b->putRevision('admin.js', 'from-b');

        $this->assertSame(['forum.js' => 'from-a', 'admin.js' => 'from-b'], $hash->store[RedisVersioner::KEY]);
        $this->assertSame(['forum.js' => 'from-a', 'admin.js' => 'from-b'], $this->versioner($hash)->allRevisions());
    }

    #[Test]
    public function redis_errors_propagate_on_read()
    {
        // Like FileVersioner's storage errors: RevisionCompiler::getUrl() treats
        // "no revision" as "recompile, and if still none, render without the
        // asset" — a swallowed read error would mean pages without CSS/JS.
        $versioner = $this->versioner($this->broken());

        $this->expectException(\RuntimeException::class);
        $versioner->getRevision('forum.js');
    }

    #[Test]
    public function redis_errors_propagate_on_write()
    {
        // RecompileFrontendAssets::recompileIfDirty() clears the shared dirty
        // flag right after commitAll() returns. A swallowed write error would
        // consume the flag while the hash still holds the old revision — stale
        // content served until the next admin action.
        $versioner = $this->versioner($this->broken());

        $this->expectException(\RuntimeException::class);
        $versioner->putRevision('forum.js', 'v1');
    }

    #[Test]
    public function it_ignores_empty_or_non_string_values_from_redis()
    {
        $hash = $this->hash();
        // '' and null: defensive; false: what phpredis returns for a missing
        // field on HGET (not used here, but keep the guard honest).
        $hash->store[RedisVersioner::KEY] = ['forum.js' => 'ok', 'admin.js' => '', 'x.js' => null, 'y.js' => false];

        $this->assertSame(['forum.js' => 'ok'], $this->versioner($hash)->allRevisions());
    }

    #[Test]
    public function the_key_is_configurable_for_prefixing()
    {
        $hash = $this->hash();
        $versioner = new RedisVersioner($this->factory($hash), 'fof.cache', 'site-a:'.RedisVersioner::KEY);

        $versioner->putRevision('forum.js', 'v1');

        $this->assertArrayHasKey('site-a:'.RedisVersioner::KEY, $hash->store);
        $this->assertArrayNotHasKey(RedisVersioner::KEY, $hash->store);
    }

    // --- fakes -----------------------------------------------------------

    protected function versioner(object $connection): RedisVersioner
    {
        return new RedisVersioner($this->factory($connection), 'fof.cache', RedisVersioner::KEY);
    }

    protected function factory(object $connection): Factory
    {
        return new class($connection) implements Factory {
            public function __construct(protected object $connection)
            {
            }

            public function connection($name = null)
            {
                return $this->connection;
            }
        };
    }

    /**
     * In-memory stand-in for the subset of Redis hash commands the versioner
     * uses, recording every command it receives.
     */
    protected function hash(): object
    {
        return new class() {
            /** @var array<string, array<string, mixed>> */
            public array $store = [];

            /** @var list<array{0: string, 1: string}> command name + key */
            public array $log = [];

            public function hgetall(string $key): array
            {
                $this->log[] = ['hgetall', $key];

                return $this->store[$key] ?? [];
            }

            public function hset(string $key, string $field, string $value): int
            {
                $this->log[] = ['hset', $key];
                $this->store[$key][$field] = $value;

                return 1;
            }

            public function hdel(string $key, string $field): int
            {
                $this->log[] = ['hdel', $key];
                unset($this->store[$key][$field]);

                return 1;
            }
        };
    }

    protected function broken(): object
    {
        return new class() {
            public function __call(string $method, array $arguments): mixed
            {
                throw new \RuntimeException('Connection refused');
            }
        };
    }
}

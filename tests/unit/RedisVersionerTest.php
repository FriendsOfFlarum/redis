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
use PHPUnit\Framework\TestCase;

class RedisVersionerTest extends TestCase
{
    /**
     * @test
     */
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

    /**
     * @test
     */
    public function reads_are_never_stale_across_instances()
    {
        $hash = $this->hash();
        $a = $this->versioner($hash);
        $b = $this->versioner($hash);

        $this->assertNull($a->getRevision('forum.js'));

        $b->putRevision('forum.js', 'v1');

        // No memo on 1.x: A sees B's write on its very next read.
        $this->assertSame('v1', $a->getRevision('forum.js'));
    }

    /**
     * @test
     */
    public function the_write_path_only_ever_issues_single_field_commands()
    {
        // Atomicity comes from the command choice: a per-field HSET/HDEL cannot
        // lose a concurrent writer's update, whereas anything that reads the
        // whole hash and writes it back (HGETALL + HMSET/DEL) can. Pin the
        // commands the write path uses, so a refactor towards read-modify-write
        // fails this test.
        $hash = $this->hash();
        $versioner = $this->versioner($hash);

        $versioner->putRevision('forum.js', 'v1');
        $versioner->putRevision('admin.js', null);

        $this->assertSame(['hset', 'hdel'], array_column($hash->log, 0));
    }

    /**
     * @test
     */
    public function two_instances_write_independent_fields_and_a_fresh_reader_sees_both()
    {
        $hash = $this->hash();
        $a = $this->versioner($hash);
        $b = $this->versioner($hash);

        $a->putRevision('forum.js', 'from-a');
        $b->putRevision('admin.js', 'from-b');

        $this->assertSame(['forum.js' => 'from-a', 'admin.js' => 'from-b'], $hash->store[RedisVersioner::KEY]);
        $this->assertSame(['forum.js' => 'from-a', 'admin.js' => 'from-b'], $this->versioner($hash)->allRevisions());
    }

    /**
     * @test
     */
    public function redis_errors_propagate_on_read()
    {
        // Like FileVersioner's storage errors: RevisionCompiler::getUrl() treats
        // "no revision" as "recompile, and if still none, render without the
        // asset" — a swallowed read error would mean pages without CSS/JS.
        $versioner = $this->versioner($this->broken());

        $this->expectException(\RuntimeException::class);
        $versioner->getRevision('forum.js');
    }

    /**
     * @test
     */
    public function redis_errors_propagate_on_write()
    {
        // A swallowed write error would leave a stale revision in the hash with
        // nothing to trigger a retry — stale content until the next admin action.
        $versioner = $this->versioner($this->broken());

        $this->expectException(\RuntimeException::class);
        $versioner->putRevision('forum.js', 'v1');
    }

    /**
     * @test
     */
    public function a_missing_field_reads_as_no_revision_with_either_client()
    {
        // phpredis returns false for a missing hash field, Predis returns null.
        $hash = $this->hash();
        $hash->missingFieldValue = false;
        $this->assertNull($this->versioner($hash)->getRevision('forum.js'));

        $hash->missingFieldValue = null;
        $this->assertNull($this->versioner($hash)->getRevision('forum.js'));
    }

    /**
     * @test
     */
    public function it_ignores_empty_or_non_string_values_from_redis()
    {
        $hash = $this->hash();
        $hash->store[RedisVersioner::KEY] = ['forum.js' => 'ok', 'admin.js' => '', 'x.js' => null];

        $versioner = $this->versioner($hash);

        $this->assertSame(['forum.js' => 'ok'], $versioner->allRevisions());
        $this->assertNull($versioner->getRevision('admin.js'));
        $this->assertNull($versioner->getRevision('x.js'));
    }

    /**
     * @test
     */
    public function the_key_is_configurable_for_prefixing()
    {
        $hash = $this->hash();
        $versioner = new RedisVersioner($this->factory($hash), 'fof.cache', 'site-a:'.RedisVersioner::KEY);

        $versioner->putRevision('forum.js', 'v1');

        $this->assertArrayHasKey('site-a:'.RedisVersioner::KEY, $hash->store);
        $this->assertArrayNotHasKey(RedisVersioner::KEY, $hash->store);
    }

    // --- fakes -----------------------------------------------------------

    protected function versioner($connection): RedisVersioner
    {
        return new RedisVersioner($this->factory($connection), 'fof.cache', RedisVersioner::KEY);
    }

    protected function factory($connection): Factory
    {
        return new class($connection) implements Factory {
            /** @var object */
            protected $connection;

            public function __construct($connection)
            {
                $this->connection = $connection;
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
    protected function hash()
    {
        return new class() {
            /** @var array<string, array<string, mixed>> */
            public $store = [];

            /** @var array<int, array<int, string>> command name + key */
            public $log = [];

            /** @var mixed what HGET returns for a missing field (false on phpredis, null on Predis) */
            public $missingFieldValue = null;

            public function hget(string $key, string $field)
            {
                $this->log[] = ['hget', $key];

                return array_key_exists($field, $this->store[$key] ?? []) ? $this->store[$key][$field] : $this->missingFieldValue;
            }

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

    protected function broken()
    {
        return new class() {
            public function __call(string $method, array $arguments)
            {
                throw new \RuntimeException('Connection refused');
            }
        };
    }
}

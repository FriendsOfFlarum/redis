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

namespace FoF\Redis\Tests\integration;

use Flarum\Frontend\Compiler\FileVersioner;
use Flarum\Frontend\Compiler\JsCompiler;
use Flarum\Frontend\Compiler\VersionerInterface;
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Assets\RedisVersioner;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Redis\Factory;
use PHPUnit\Framework\Attributes\Test;

class AssetRevisionsTest extends TestCase
{
    use RedisTestConfig;

    protected function tearDown(): void
    {
        $this->flushTestDatabases();

        parent::tearDown();
    }

    #[Test]
    public function with_pubsub_enabled_the_redis_versioner_is_bound_by_default()
    {
        $this->registerRedis();

        $container = $this->app()->getContainer();

        $this->assertInstanceOf(RedisVersioner::class, $container->make(VersionerInterface::class));
        $this->assertSame(
            $container->make(VersionerInterface::class),
            $container->make(VersionerInterface::class),
            'The versioner must be a singleton so the per-request memo is shared'
        );
    }

    #[Test]
    public function core_compilers_receive_the_redis_versioner()
    {
        $this->registerRedis();

        $container = $this->app()->getContainer();

        $compiler = $container->make(JsCompiler::class, [
            'assetsDir' => $container->make(FilesystemFactory::class)->disk('flarum-assets'),
            'filename'  => 'forum.js',
        ]);

        $versioner = (new \ReflectionProperty($compiler, 'versioner'))->getValue($compiler);

        $this->assertInstanceOf(RedisVersioner::class, $versioner, 'RevisionCompiler must be built with the Redis versioner, not core\'s FileVersioner');
    }

    #[Test]
    public function revisions_round_trip_through_the_configured_redis_connection()
    {
        $this->registerRedis();

        $container = $this->app()->getContainer();
        /** @var RedisVersioner $versioner */
        $versioner = $container->make(VersionerInterface::class);

        $versioner->putRevision('forum.js', 'deadbeef');
        $versioner->putRevision('forum-de.js', 'cafe');

        $this->assertSame('deadbeef', $versioner->getRevision('forum.js'));
        $this->assertSame(['forum.js' => 'deadbeef', 'forum-de.js' => 'cafe'], $versioner->allRevisions());

        // Stored as a single hash on the cache connection — one atomic field per asset.
        $raw = $container->make(Factory::class)->connection('fof.cache')->hgetall(RedisVersioner::KEY);
        $this->assertSame(['forum.js' => 'deadbeef', 'forum-de.js' => 'cafe'], $raw);

        $versioner->putRevision('forum.js', null);
        $this->assertNull($versioner->getRevision('forum.js'));
        $this->assertSame(['forum-de.js' => 'cafe'], $container->make(Factory::class)->connection('fof.cache')->hgetall(RedisVersioner::KEY));
    }

    #[Test]
    public function two_instances_write_independent_fields_and_a_fresh_reader_sees_both()
    {
        // Documentation-by-test: atomicity itself comes from HSET/HDEL being
        // single-field commands (pinned in the unit test); this shows the
        // resulting semantics against real Redis.
        $this->registerRedis();

        $container = $this->app()->getContainer();
        $redis = $container->make(Factory::class);

        $a = new RedisVersioner($redis, 'fof.cache');
        $b = new RedisVersioner($redis, 'fof.cache');
        $a->allRevisions();
        $b->allRevisions();

        $a->putRevision('forum.js', 'from-a');
        $b->putRevision('admin.js', 'from-b');

        $this->assertSame(
            ['forum.js' => 'from-a', 'admin.js' => 'from-b'],
            (new RedisVersioner($redis, 'fof.cache'))->allRevisions()
        );
    }

    #[Test]
    public function core_compilers_record_their_revisions_in_the_hash_end_to_end()
    {
        $this->registerRedis();

        $container = $this->app()->getContainer();
        // Resolve the registered abstract directly: core's AssetManager::frontend()
        // checks in_array() against the map's VALUES, so frontend('forum') throws
        // "Unknown frontend" for every registered frontend (core 2.x bug).
        $forum = $container->make('flarum.assets.forum');

        // Exactly what Content\Assets does when rendering a page: getUrl()
        // finds no revision, compiles, records one, and returns a busted URL.
        $jsUrl = $forum->makeJs()->getUrl();
        $cssUrl = $forum->makeCss()->getUrl();

        $this->assertStringContainsString('forum.js?v=', (string) $jsUrl);
        $this->assertStringContainsString('forum.css?v=', (string) $cssUrl);

        $recorded = $this->rawRedis($this->testCacheDb)->hgetall(RedisVersioner::KEY);

        $this->assertArrayHasKey('forum.js', $recorded, 'The JS compiler must have written its revision to the Redis hash, not to rev-manifest.json');
        $this->assertArrayHasKey('forum.css', $recorded, 'The LESS compiler must have written its revision to the Redis hash, not to rev-manifest.json');
        $this->assertStringEndsWith('?v='.$recorded['forum.js'], $jsUrl);
    }

    #[Test]
    public function the_file_versioner_can_be_kept_explicitly()
    {
        $this->registerRedis([], ['asset_revisions' => 'file']);

        $this->assertInstanceOf(FileVersioner::class, $this->app()->getContainer()->make(VersionerInterface::class));
    }

    #[Test]
    public function without_pubsub_the_file_versioner_stays_unless_opted_in()
    {
        $this->registerRedis([], ['pubsub' => ['enabled' => false, 'autostart' => false]]);

        $this->assertInstanceOf(FileVersioner::class, $this->app()->getContainer()->make(VersionerInterface::class));
    }

    #[Test]
    public function it_can_be_opted_in_without_pubsub()
    {
        $this->registerRedis([], ['pubsub' => ['enabled' => false, 'autostart' => false], 'asset_revisions' => 'redis']);

        $this->assertInstanceOf(RedisVersioner::class, $this->app()->getContainer()->make(VersionerInterface::class));
    }

    #[Test]
    public function the_hash_key_is_isolated_by_the_configured_prefix()
    {
        $this->registerRedis([], ['prefix' => 'site-a:']);

        $this->app()->getContainer()->make(VersionerInterface::class)->putRevision('forum.js', 'v1');

        // Read through a RAW client (no client-side prefix), so this can fail if
        // the key is not actually namespaced on the wire. The client applies the
        // configured prefix on top of the one the extension prepends, which is
        // the same convention every other key on this connection follows.
        $keys = $this->rawRedis($this->testCacheDb)->keys('*'.RedisVersioner::KEY);

        $this->assertCount(1, $keys);
        $this->assertStringStartsWith('site-a:', $keys[0]);
        $this->assertStringEndsWith(RedisVersioner::KEY, $keys[0]);
        $this->assertSame(['forum.js' => 'v1'], $this->rawRedis($this->testCacheDb)->hgetall($keys[0]));
    }
}

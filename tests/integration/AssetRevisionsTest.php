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
use FoF\Redis\Extend\Redis as RedisExtender;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Redis\Factory;

class AssetRevisionsTest extends TestCase
{
    protected function tearDown(): void
    {
        // Remove what the tests wrote, whatever prefix it landed under.
        try {
            $raw = $this->rawRedis();
            foreach ($raw->keys('*'.RedisVersioner::KEY) as $key) {
                $raw->del($key);
            }
        } catch (\Throwable $e) {
            // Redis unavailable — nothing to clean.
        }

        parent::tearDown();
    }

    /**
     * @test
     */
    public function with_pubsub_enabled_the_redis_versioner_is_bound_by_default()
    {
        $this->registerRedis();
        $this->requiresRedis();

        $container = $this->app()->getContainer();

        $this->assertTrue($container->bound(VersionerInterface::class));
        $this->assertInstanceOf(RedisVersioner::class, $container->make(VersionerInterface::class));
        $this->assertSame(
            $container->make(VersionerInterface::class),
            $container->make(VersionerInterface::class),
            'The versioner is a singleton — one Redis client path, no per-compiler instances'
        );
    }

    /**
     * @test
     */
    public function core_compilers_receive_the_redis_versioner()
    {
        $this->registerRedis();
        $this->requiresRedis();

        $this->assertInstanceOf(
            RedisVersioner::class,
            $this->versionerOfAFreshCompiler(),
            'RevisionCompiler\'s optional VersionerInterface must be satisfied by the container binding, not by its FileVersioner default'
        );
    }

    /**
     * @test
     */
    public function revisions_round_trip_through_the_configured_redis_connection()
    {
        $this->registerRedis();
        $this->requiresRedis();

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

    /**
     * @test
     */
    public function two_instances_write_independent_fields_and_a_fresh_reader_sees_both()
    {
        // Documentation-by-test: atomicity itself comes from HSET/HDEL being
        // single-field commands (pinned in the unit test); this shows the
        // resulting semantics against real Redis.
        $this->registerRedis();
        $this->requiresRedis();

        $redis = $this->app()->getContainer()->make(Factory::class);

        $a = new RedisVersioner($redis, 'fof.cache');
        $b = new RedisVersioner($redis, 'fof.cache');

        $a->putRevision('forum.js', 'from-a');
        $b->putRevision('admin.js', 'from-b');

        $this->assertSame('from-a', $b->getRevision('forum.js'), 'No memo on 1.x: B sees A\'s write on its next read');
        $this->assertSame(
            ['forum.js' => 'from-a', 'admin.js' => 'from-b'],
            (new RedisVersioner($redis, 'fof.cache'))->allRevisions()
        );
    }

    /**
     * @test
     */
    public function core_compilers_record_their_revisions_in_the_hash_end_to_end()
    {
        $this->registerRedis();
        $this->requiresRedis();

        // Exactly what Content\Assets does when rendering a page: getUrl()
        // finds no revision, compiles, records one, and returns a busted URL.
        $forum = $this->app()->getContainer()->make('flarum.assets.forum');
        $jsUrl = $forum->makeJs()->getUrl();
        $cssUrl = $forum->makeCss()->getUrl();

        $this->assertStringContainsString('forum.js?v=', (string) $jsUrl);
        $this->assertStringContainsString('forum.css?v=', (string) $cssUrl);

        $recorded = $this->rawRedis()->hgetall(RedisVersioner::KEY);

        $this->assertArrayHasKey('forum.js', $recorded, 'The JS compiler must have written its revision to the Redis hash, not to rev-manifest.json');
        $this->assertArrayHasKey('forum.css', $recorded, 'The LESS compiler must have written its revision to the Redis hash, not to rev-manifest.json');
        $this->assertStringEndsWith('?v='.$recorded['forum.js'], $jsUrl);
    }

    /**
     * @test
     */
    public function the_file_versioner_can_be_kept_explicitly()
    {
        $this->registerRedis(['asset_revisions' => 'file']);
        $this->requiresRedis();

        $this->assertFalse($this->app()->getContainer()->bound(VersionerInterface::class));
        $this->assertInstanceOf(FileVersioner::class, $this->versionerOfAFreshCompiler());
    }

    /**
     * @test
     */
    public function without_pubsub_the_file_versioner_stays_unless_opted_in()
    {
        $this->registerRedis(['pubsub' => ['enabled' => false, 'autostart' => false]]);
        $this->requiresRedis();

        $this->assertFalse($this->app()->getContainer()->bound(VersionerInterface::class));
        $this->assertInstanceOf(FileVersioner::class, $this->versionerOfAFreshCompiler());
    }

    /**
     * @test
     */
    public function it_can_be_opted_in_without_pubsub()
    {
        $this->registerRedis(['pubsub' => ['enabled' => false, 'autostart' => false], 'asset_revisions' => 'redis']);
        $this->requiresRedis();

        $this->assertInstanceOf(RedisVersioner::class, $this->versionerOfAFreshCompiler());
    }

    /**
     * @test
     */
    public function the_hash_key_is_isolated_by_the_configured_prefix()
    {
        $this->registerRedis(['prefix' => 'site-a:']);
        $this->requiresRedis();

        $this->app()->getContainer()->make(VersionerInterface::class)->putRevision('forum.js', 'v1');

        // Read through a RAW client (no client-side prefix), so this can fail if
        // the key is not actually namespaced on the wire. The client applies the
        // configured prefix on top of the one the extension prepends, which is
        // the same convention every other key on this connection follows.
        $keys = $this->rawRedis()->keys('*'.RedisVersioner::KEY);

        $this->assertCount(1, $keys);
        $this->assertStringStartsWith('site-a:', $keys[0]);
        $this->assertStringEndsWith(RedisVersioner::KEY, $keys[0]);
        $this->assertSame(['forum.js' => 'v1'], $this->rawRedis()->hgetall($keys[0]));
    }

    // --- helpers ---------------------------------------------------------

    /**
     * A client on the test database with NO prefix and no app involvement, to
     * observe what is actually stored.
     *
     * @return \Redis|\Predis\Client
     */
    private function rawRedis()
    {
        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        if (extension_loaded('redis')) {
            $client = new \Redis();
            $client->connect($host, $port);
            $client->select(15);

            return $client;
        }

        return new \Predis\Client(['scheme' => 'tcp', 'host' => $host, 'port' => $port, 'database' => 15]);
    }

    /**
     * Register the extender the way the other 1.x tests do, with pub/sub on
     * by default; $overrides replace top-level keys.
     */
    private function registerRedis(array $overrides = []): void
    {
        $this->extend(
            new RedisExtender(array_replace([
                'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
                'password' => getenv('REDIS_PASSWORD') ?: null,
                'port'     => getenv('REDIS_PORT') ?: 6379,
                'database' => 15,
                'pubsub'   => [
                    'enabled'   => true,
                    'autostart' => false,
                ],
            ], $overrides))
        );
    }

    /**
     * The versioner core's compilers actually end up with: resolve a JsCompiler
     * exactly like Frontend\Assets does and read the property it was built with.
     */
    private function versionerOfAFreshCompiler(): VersionerInterface
    {
        $container = $this->app()->getContainer();

        $compiler = $container->make(JsCompiler::class, [
            'assetsDir' => $container->make(FilesystemFactory::class)->disk('flarum-assets'),
            'filename'  => 'forum.js',
        ]);

        $property = new \ReflectionProperty($compiler, 'versioner');
        $property->setAccessible(true);

        return $property->getValue($compiler);
    }

    private function requiresRedis(): void
    {
        try {
            $result = $this->app()->getContainer()->make(Factory::class)->connection('fof.cache')->ping();

            if ($result === false || ((string) $result !== 'PONG' && $result !== true)) {
                $this->markTestSkipped('Redis is not available: ping returned '.var_export($result, true));
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not available: '.$e->getMessage());
        }
    }
}

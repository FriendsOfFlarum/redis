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

use Flarum\Foundation\Event\ClearingCache;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Settings\RedisCacheSettingsRepository;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;

class SettingsTest extends TestCase
{
    use RedisTestConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushTestDatabases();
        $this->registerRedis();
    }

    protected function tearDown(): void
    {
        $this->flushTestDatabases();

        parent::tearDown();
    }

    protected function settings(): SettingsRepositoryInterface
    {
        return $this->app()->getContainer()->make(SettingsRepositoryInterface::class);
    }

    /**
     * The Redis-backed cache layer for settings.
     */
    protected function settingsCache()
    {
        return $this->app()->getContainer()->make('cache.settings');
    }

    #[Test]
    public function the_settings_repository_is_backed_by_the_redis_cache_layer()
    {
        // The bound repository wraps RedisCacheSettingsRepository somewhere in
        // its decorator chain; a plain string read should still work.
        $settings = $this->settings();

        $settings->set('fof-redis.probe', 'hello');
        $this->assertSame('hello', $settings->get('fof-redis.probe'));
    }

    #[Test]
    public function reading_settings_populates_the_redis_cache()
    {
        // Target the Redis layer directly: in the full chain a per-request
        // MemoryCacheSettingsRepository sits in front of it, so once the app
        // has read settings during boot the memory layer would answer without
        // ever touching Redis. This asserts the Redis layer's own fill.
        $inner = $this->app()->getContainer()->make(\Flarum\Settings\DatabaseSettingsRepository::class);
        $repo = new RedisCacheSettingsRepository($inner, $this->settingsCache());

        $this->settingsCache()->forget('flarum:settings');
        $this->assertFalse($this->settingsCache()->has('flarum:settings'));

        $all = $repo->all();

        $this->assertIsArray($all);
        $this->assertTrue($this->settingsCache()->has('flarum:settings'));
        $this->assertIsArray($this->settingsCache()->get('flarum:settings'));
    }

    #[Test]
    public function set_updates_the_cached_array_in_place()
    {
        // Warm the cache.
        $this->settings()->all();
        $this->assertIsArray($this->settingsCache()->get('flarum:settings'));

        // Setting a value patches the cached array rather than dropping it,
        // so a stale read-replica cannot re-populate an old value.
        $this->settings()->set('fof-redis.inplace', 'patched');

        $cached = $this->settingsCache()->get('flarum:settings');
        $this->assertArrayHasKey('fof-redis.inplace', $cached);
        $this->assertSame('patched', $cached['fof-redis.inplace']);
    }

    #[Test]
    public function set_keeps_the_flexible_created_timestamp_in_sync()
    {
        // all() stores via cache->flexible(), which writes TWO keys: the value
        // and a companion 'illuminate:cache:flexible:created:flarum:settings'
        // timestamp used to decide staleness. If set() patches only the value
        // and leaves the created timestamp old, the next all() judges the
        // freshly-set value STALE and defers a refresh from the database —
        // which, on a lagging read replica, re-populates the OLD value,
        // defeating the in-place patch's whole purpose. So set() must keep the
        // created timestamp in sync with the value it writes.
        $cache = $this->settingsCache();
        $inner = $this->app()->getContainer()->make(\Flarum\Settings\DatabaseSettingsRepository::class);
        $repo = new RedisCacheSettingsRepository($inner, $cache);
        $createdKey = 'illuminate:cache:flexible:created:flarum:settings';

        // Warm the cache (writes value + created).
        $repo->all();

        // Age the created timestamp into the stale window (ttl[0] = 3600-300 =
        // 3300s), as if the cache had been filled ~an hour ago.
        $cache->forever($createdKey, \Illuminate\Support\Carbon::now()->subSeconds(3400)->getTimestamp());

        // Set a new value through the repo.
        $repo->set('fof-redis.sync', 'fresh-value');

        // The created timestamp must now be recent — NOT still in the stale
        // window — so the value we just set is treated as fresh.
        $created = (int) $cache->get($createdKey);
        $this->assertGreaterThan(
            \Illuminate\Support\Carbon::now()->subSeconds(3300)->getTimestamp(),
            $created,
            'set() must refresh the flexible created-timestamp so the new value is not immediately stale'
        );
    }

    #[Test]
    public function delete_removes_the_key_from_the_cached_array_in_place()
    {
        $this->settings()->set('fof-redis.temp', 'value');
        $this->settings()->all();
        $this->assertArrayHasKey('fof-redis.temp', $this->settingsCache()->get('flarum:settings'));

        $this->settings()->delete('fof-redis.temp');

        $cached = $this->settingsCache()->get('flarum:settings');
        $this->assertIsArray($cached);
        $this->assertArrayNotHasKey('fof-redis.temp', $cached);
    }

    #[Test]
    public function clearing_cache_event_invalidates_the_settings_cache()
    {
        $this->settings()->all();
        $this->assertTrue($this->settingsCache()->has('flarum:settings'));

        $this->app()->getContainer()->make(Dispatcher::class)->dispatch(new ClearingCache());

        $this->assertFalse(
            $this->settingsCache()->has('flarum:settings'),
            'the ClearingCache event should forget the Redis settings cache'
        );
    }

    #[Test]
    public function all_guards_against_reentrant_calls()
    {
        // The RedisCacheSettingsRepository sets a $loading flag while filling
        // the cache and returns [] to a re-entrant all() call, preventing the
        // infinite recursion that a DB event reading settings mid-query would
        // otherwise cause. Drive that directly with a fake inner repository
        // that calls back into all() while being read.
        $cache = $this->settingsCache();
        $cache->forget('flarum:settings');

        $inner = new class() implements SettingsRepositoryInterface {
            public ?RedisCacheSettingsRepository $outer = null;
            public int $reentrantResult = -1;

            public function all(): array
            {
                // Re-enter while the outer repository is mid-fill.
                $this->reentrantResult = count($this->outer->all());

                return ['committed' => '1'];
            }

            public function get(string $key, $default = null): mixed
            {
                return $default;
            }

            public function set(string $key, $value): void
            {
            }

            public function delete(string $key): void
            {
            }
        };

        $repo = new RedisCacheSettingsRepository($inner, $cache);
        $inner->outer = $repo;

        $result = $repo->all();

        // The re-entrant call returned early with [] instead of recursing.
        $this->assertSame(0, $inner->reentrantResult);
        // The outer call still produced the real value.
        $this->assertSame(['committed' => '1'], $result);
    }
}

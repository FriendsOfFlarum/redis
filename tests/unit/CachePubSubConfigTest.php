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

use FoF\Redis\Provides\Cache;
use PHPUnit\Framework\TestCase;

class CachePubSubConfigTest extends TestCase
{
    /**
     * @test
     */
    public function normalize_pubsub_config_defaults_are_applied()
    {
        $cache = new Cache();
        $config = $this->invokeNormalize($cache, []);

        $this->assertSame(false, $config['enabled']);
        $this->assertSame(false, $config['autostart']);
        $this->assertSame('flarum:cache:invalidate', $config['channel']);
        $this->assertSame(0, $config['delay']);
        $this->assertSame(300, $config['spawn_lock_ttl']);
    }

    /**
     * @test
     */
    public function normalize_pubsub_config_enables_autostart_by_default_when_enabled()
    {
        $cache = new Cache();
        $config = $this->invokeNormalize($cache, ['enabled' => true]);

        $this->assertSame(true, $config['enabled']);
        $this->assertSame(true, $config['autostart']);
    }

    /**
     * @test
     */
    public function normalize_pubsub_config_autostart_forces_enabled()
    {
        $cache = new Cache();
        $config = $this->invokeNormalize($cache, ['enabled' => false, 'autostart' => true]);

        $this->assertSame(true, $config['enabled']);
        $this->assertSame(true, $config['autostart']);
    }

    /**
     * @test
     */
    public function normalize_pubsub_config_respects_autostart_false()
    {
        $cache = new Cache();
        $config = $this->invokeNormalize($cache, ['enabled' => true, 'autostart' => false]);

        $this->assertSame(true, $config['enabled']);
        $this->assertSame(false, $config['autostart']);
    }

    /**
     * @test
     */
    public function normalize_pubsub_config_check_interval_defaults_to_every_request()
    {
        $cache = new Cache();
        $config = $this->invokeNormalize($cache, ['enabled' => true]);

        $this->assertSame(0, $config['check_interval']);
    }

    /**
     * @test
     */
    public function normalize_pubsub_config_check_interval_is_configurable_and_never_negative()
    {
        $cache = new Cache();

        $config = $this->invokeNormalize($cache, ['enabled' => true, 'check_interval' => 5]);
        $this->assertSame(5, $config['check_interval']);

        $config = $this->invokeNormalize($cache, ['enabled' => true, 'check_interval' => -3]);
        $this->assertSame(0, $config['check_interval']);
    }

    /**
     * @test
     */
    public function normalize_pubsub_config_version_key_honors_the_prefix()
    {
        $cache = new Cache();

        $config = $this->invokeNormalize($cache, []);
        $this->assertSame('flarum:cache:version', $config['version_key']);

        $config = $this->invokeNormalize($cache, [], 'forum_a:');
        $this->assertSame('forum_a:flarum:cache:version', $config['version_key']);
    }

    private function invokeNormalize(Cache $cache, array $config, string $prefix = ''): array
    {
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('normalizePubSubConfig');
        $method->setAccessible(true);

        return $method->invoke($cache, $config, $prefix);
    }
}

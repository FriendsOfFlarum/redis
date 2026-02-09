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

    private function invokeNormalize(Cache $cache, array $config): array
    {
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('normalizePubSubConfig');
        $method->setAccessible(true);

        return $method->invoke($cache, $config);
    }
}

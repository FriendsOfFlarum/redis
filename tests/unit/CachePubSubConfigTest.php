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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CachePubSubConfigTest extends TestCase
{
    #[Test]
    public function normalize_pubsub_config_defaults_are_applied()
    {
        $config = $this->invokeNormalize([]);

        $this->assertFalse($config['enabled']);
        $this->assertFalse($config['autostart']);
        $this->assertSame('flarum:cache:invalidate', $config['channel']);
        $this->assertSame(0, $config['delay']);
        $this->assertSame(300, $config['spawn_lock_ttl']);
        $this->assertSame(0, $config['check_interval']);
    }

    #[Test]
    public function normalize_pubsub_config_check_interval_is_configurable_and_never_negative()
    {
        $config = $this->invokeNormalize(['enabled' => true, 'check_interval' => 5]);
        $this->assertSame(5, $config['check_interval']);

        $config = $this->invokeNormalize(['enabled' => true, 'check_interval' => -3]);
        $this->assertSame(0, $config['check_interval']);
    }

    #[Test]
    public function normalize_pubsub_config_version_key_honors_the_prefix()
    {
        $config = $this->invokeNormalize([]);
        $this->assertSame('flarum:cache:version', $config['version_key']);

        $config = $this->invokeNormalize([], 'forum_a:');
        $this->assertSame('forum_a:flarum:cache:version', $config['version_key']);
    }

    private function invokeNormalize(array $config, string $prefix = ''): array
    {
        $method = (new \ReflectionClass(Cache::class))->getMethod('normalizePubSubConfig');

        return $method->invoke(new Cache(), $config, $prefix);
    }
}

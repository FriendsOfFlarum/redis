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

use Flarum\Foundation\Paths;
use FoF\Redis\Provides\Cache;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CacheSubscriberSpawnTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/fof-redis-spawn-'.bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->base.'/*') ?: []);
        rmdir($this->base);
    }

    #[Test]
    public function a_web_request_does_not_spawn_the_subscriber()
    {
        // Under PHP-FPM, PHP_BINARY is the FPM binary, which cannot run
        // `flarum cache:subscribe`: the spawn would die before booting.
        $this->invokeStartSubscriber('fpm-fcgi');

        $this->assertFileDoesNotExist($this->base.'/cache-subscriber.lock');
        $this->assertFileDoesNotExist($this->base.'/cache-subscriber.spawn.lock');
    }

    #[Test]
    public function a_cli_process_still_spawns_the_subscriber()
    {
        // The temp base has no `flarum` script, so the spawned process exits at once.
        $this->invokeStartSubscriber('cli');

        // The spawner pre-claims the subscriber lock until the daemon writes its PID.
        $this->assertFileExists($this->base.'/cache-subscriber.lock');
        $this->assertSame('0', file_get_contents($this->base.'/cache-subscriber.lock'));
    }

    private function invokeStartSubscriber(string $sapi): void
    {
        $container = new Container();
        $container->instance(Paths::class, new Paths([
            'base'    => $this->base,
            'public'  => $this->base,
            'storage' => $this->base,
        ]));
        $container->instance(\Psr\Log\LoggerInterface::class, new NullLogger());

        $config = (new \ReflectionClass(Cache::class))->getMethod('normalizePubSubConfig')
            ->invoke(new Cache(), ['autostart' => true]);

        (new \ReflectionClass(Cache::class))->getMethod('startSubscriber')
            ->invoke(new Cache(), $container, $config, $sapi);
    }
}

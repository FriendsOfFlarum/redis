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

class CacheSubscriberLockTest extends TestCase
{
    /**
     * @test
     */
    public function acquire_spawn_lock_respects_stale_ttl()
    {
        $cache = new Cache();
        $lockFile = $this->tempPath('spawn.lock');

        // Create a stale lock file.
        file_put_contents($lockFile, '1');
        touch($lockFile, time() - 400);

        $acquired = $this->invokeAcquire($cache, $lockFile, 300);

        $this->assertTrue($acquired, 'Should acquire lock after stale file cleanup');
        $this->assertFileExists($lockFile);

        $this->invokeRelease($cache, $lockFile);
        $this->assertFileDoesNotExist($lockFile);
    }

    /**
     * @test
     */
    public function acquire_spawn_lock_fails_when_lock_is_fresh()
    {
        $cache = new Cache();
        $lockFile = $this->tempPath('spawn-fresh.lock');

        file_put_contents($lockFile, '1');
        touch($lockFile, time());

        $acquired = $this->invokeAcquire($cache, $lockFile, 300);

        $this->assertFalse($acquired, 'Should not acquire lock when lock is fresh');
        $this->assertFileExists($lockFile);

        @unlink($lockFile);
    }

    /**
     * @test
     */
    public function is_already_running_cleans_up_stale_lock()
    {
        $cache = new Cache();
        $lockFile = $this->tempPath('subscriber.lock');

        // PID that should not exist.
        file_put_contents($lockFile, '999999');

        $running = $this->invokeIsAlreadyRunning($cache, $lockFile, 300);

        $this->assertFalse($running);
        $this->assertFileDoesNotExist($lockFile);
    }

    private function tempPath(string $name): string
    {
        $dir = sys_get_temp_dir().'/fof-redis-tests';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir.'/'.$name;
    }

    private function invokeAcquire(Cache $cache, string $lockFile, int $ttl): bool
    {
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('acquireSpawnLock');
        $method->setAccessible(true);

        return $method->invoke($cache, $lockFile, $ttl);
    }

    private function invokeRelease(Cache $cache, string $lockFile): void
    {
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('releaseSpawnLock');
        $method->setAccessible(true);

        $method->invoke($cache, $lockFile);
    }

    private function invokeIsAlreadyRunning(Cache $cache, string $lockFile, int $ttl): bool
    {
        $reflection = new \ReflectionClass($cache);
        $method = $reflection->getMethod('isAlreadyRunning');
        $method->setAccessible(true);

        return $method->invoke($cache, $lockFile, $ttl);
    }
}

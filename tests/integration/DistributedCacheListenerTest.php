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
use Flarum\Foundation\Paths;
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Console\ListenDistributedCacheInvalidationCommand;
use FoF\Redis\Extend\Redis as RedisExtender;
use FoF\Redis\Service\DistributedCacheInvalidationService;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Psr\Log\LoggerInterface;

class DistributedCacheListenerTest extends TestCase
{
    private const EMITTER_POD = 'pod-emitter';
    private const LISTENER_POD = 'pod-listener';
    private const TEST_FILESTORE_KEY = 'test.formatter.distributed-cache';
    private const CACHE_FILE_MAP = [
        'formatter' => 'redis-test-formatter.php',
        'locale'    => 'redis-test-locale.php',
        'views'     => 'redis-test-view.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extend(
            new RedisExtender([
                'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
                'password' => getenv('REDIS_PASSWORD') ?: null,
                'port'     => getenv('REDIS_PORT') ?: 6379,
                'database' => 15,
            ])
        );

        $this->purgeRedisKeys();
        $this->cleanupCacheFiles();
        $this->cleanupFilestore();
    }

    protected function tearDown(): void
    {
        $this->purgeRedisKeys();
        $this->cleanupCacheFiles();
        $this->cleanupFilestore();

        parent::tearDown();
    }

    /** @test */
    public function command_clears_local_caches_after_cache_clear_event(): void
    {
        $this->requiresRedis();

        $container = $this->app()->getContainer();
        $redis = $this->redis();
        $events = $container->make(Dispatcher::class);
        $paths = $container->make(Paths::class);

        $this->bindServiceForPod(self::EMITTER_POD);
        $events->dispatch(new ClearingCache());

        $globalVersion = (int) $redis->get('flarum:cache:version');
        $this->assertGreaterThan(0, $globalVersion, 'Cache provider should publish a global version');

        $listenerService = $this->bindServiceForPod(self::LISTENER_POD);
        $listenerService->updateLastSeenVersion(max($globalVersion - 1, 0));

        foreach (self::CACHE_FILE_MAP as $directory => $filename) {
            $dirPath = $paths->storage.'/'.$directory;
            @mkdir($dirPath, 0755, true);
            file_put_contents($dirPath.'/'.$filename, 'cache-'.$directory);
            $this->assertFileExists($dirPath.'/'.$filename);
        }

        $fileStore = new Repository($container->make('cache.filestore'));
        $fileStore->forever(self::TEST_FILESTORE_KEY, 'formatter payload');
        $this->assertSame('formatter payload', $fileStore->get(self::TEST_FILESTORE_KEY));

        $command = $this->makeCommand();
        $this->setCommandService($command, $listenerService);
        $this->attachBufferOutput($command);

        $this->invokeReconcile($command);
        clearstatcache();

        foreach (self::CACHE_FILE_MAP as $directory => $filename) {
            $this->assertFileDoesNotExist($paths->storage.'/'.$directory.'/'.$filename);
        }

        $this->assertNull($fileStore->get(self::TEST_FILESTORE_KEY));
        $this->assertEquals(
            $globalVersion,
            (int) $redis->get('flarum:cache:version:last_seen:'.self::LISTENER_POD),
            'Listener pod should record the reconciled cache version'
        );
    }

    private function makeCommand(): ListenDistributedCacheInvalidationCommand
    {
        $paths = $this->app()->getContainer()->make(Paths::class);
        $logger = $this->app()->getContainer()->make(LoggerInterface::class);

        return new ListenDistributedCacheInvalidationCommand($paths, $logger);
    }

    private function setCommandService(
        ListenDistributedCacheInvalidationCommand $command,
        DistributedCacheInvalidationService $service
    ): void {
        $ref = new \ReflectionClass($command);
        $prop = $ref->getProperty('service');
        $prop->setAccessible(true);
        $prop->setValue($command, $service);
    }

    private function invokeReconcile(ListenDistributedCacheInvalidationCommand $command): void
    {
        $ref = new \ReflectionClass($command);
        $method = $ref->getMethod('reconcileCacheVersion');
        $method->setAccessible(true);
        $method->invoke($command);
    }

    private function attachBufferOutput(ListenDistributedCacheInvalidationCommand $command): void
    {
        $ref = new \ReflectionClass($command);
        $inputProp = $ref->getParentClass()->getProperty('input');
        $inputProp->setAccessible(true);
        $inputProp->setValue($command, new \Symfony\Component\Console\Input\ArrayInput([]));

        $outputProp = $ref->getParentClass()->getProperty('output');
        $outputProp->setAccessible(true);
        $outputProp->setValue($command, new \Symfony\Component\Console\Output\BufferedOutput());
    }

    private function bindServiceForPod(string $podId): DistributedCacheInvalidationService
    {
        $factory = $this->app()->getContainer()->make(Factory::class);

        $service = new class($factory, $podId) extends DistributedCacheInvalidationService {
            private $podId;

            public function __construct(Factory $factory, string $podId)
            {
                $this->podId = $podId;
                parent::__construct($factory);
            }

            public function getPodId(): string
            {
                return $this->podId;
            }
        };

        $this->app()->getContainer()->instance(DistributedCacheInvalidationService::class, $service);

        return $service;
    }

    private function purgeRedisKeys(): void
    {
        try {
            $this->redis()->del([
                'flarum:cache:version',
                'flarum:cache:version:last_seen:'.self::EMITTER_POD,
                'flarum:cache:version:last_seen:'.self::LISTENER_POD,
            ]);
        } catch (\Throwable $e) {
            // Ignore cleanup failures so tests can proceed/skip gracefully.
        }
    }

    private function cleanupCacheFiles(): void
    {
        try {
            $paths = $this->app()->getContainer()->make(Paths::class);
        } catch (\Throwable $e) {
            return;
        }

        foreach (self::CACHE_FILE_MAP as $directory => $filename) {
            $file = $paths->storage.'/'.$directory.'/'.$filename;

            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function cleanupFilestore(): void
    {
        try {
            (new Repository($this->app()->getContainer()->make('cache.filestore')))
                ->forget(self::TEST_FILESTORE_KEY);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    private function redis(): Connection
    {
        return $this->app()->getContainer()->make(Factory::class)->connection('fof.cache');
    }

    private function requiresRedis(): void
    {
        try {
            $result = $this->redis()->ping();

            if ($result === false || ((string) $result !== 'PONG' && $result !== true)) {
                $this->markTestSkipped('Redis is not available: ping returned '.var_export($result, true));
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis is not available: '.$e->getMessage());
        }
    }
}

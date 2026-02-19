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

namespace FoF\Redis\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Foundation\Paths;
use Flarum\Locale\LocaleManager;
use FoF\Redis\Overrides\RedisManager;
use Illuminate\Cache\Repository;
use Predis\Connection\NodeConnectionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputOption;

class CacheSubscribeCommand extends AbstractCommand
{
    protected Paths $paths;
    protected LocaleManager $locales;
    protected LoggerInterface $logger;

    public function __construct(
        Paths $paths,
        LocaleManager $locales,
        LoggerInterface $logger
    ) {
        $this->paths = $paths;
        $this->locales = $locales;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('cache:subscribe')
            ->setDescription('Subscribe to Redis for distributed cache invalidation')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one message and exit (for testing)')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Delay in seconds before subscribing', 0)
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Redis pub/sub channel', 'flarum:cache:invalidate');
    }

    protected function fire(): int
    {
        $podId = gethostname();
        $delay = (int) $this->input->getOption('delay');

        if ($delay > 0) {
            $this->info("[Cache Subscriber] Delaying start by {$delay}s");
            sleep($delay);
        }

        // Check if subscription is already running in this pod
        if ($this->isAlreadyRunning()) {
            return 0;
        }

        // Create lock file to indicate subscription is active
        $this->createLockFile();

        $this->info("[Cache Subscriber] Starting on pod: {$podId}");
        $this->logger->info('[Cache Subscriber] Starting cache subscriber', ['pod' => $podId]);

        $this->info('[Cache Subscriber] Connecting to Redis...');

        // We don't use dependency injection to avoid early resolution during boot.
        try {
            /** @var RedisManager|null */
            $redis = resolve(RedisManager::class);

            if (!$redis) {
                $this->error('[Cache Subscriber] RedisManager not available - fof/redis may not be loaded');
                $this->logger->error('[Cache Subscriber] RedisManager resolution failed', ['pod' => $podId]);

                return 1;
            }

            // Verify the fof.cache connection exists
            // @phpstan-ignore-next-line
            if ($redis->connection('fof.cache') === null) {
                $this->error('[Cache Subscriber] Redis connection "fof.cache" not configured');
                $this->logger->error('[Cache Subscriber] Redis connection "fof.cache" missing', ['pod' => $podId]);

                return 1;
            }
        } catch (\Exception $e) {
            $this->error("[Cache Subscriber] Failed to resolve RedisManager: {$e->getMessage()}");
            $this->logger->error('[Cache Subscriber] RedisManager resolution error', [
                'pod'       => $podId,
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return 1;
        }

        try {
            // Get the underlying client from the connection pool
            $connection = $redis->connection('fof.cache');
            $client = $connection->client();

            // Verify we have a Predis client (fof/redis always uses Predis)
            if (!$client instanceof \Predis\Client) {
                $this->error('[Cache Subscriber] Expected Predis client, got: '.get_class($client));
                $this->logger->error('[Cache Subscriber] Unexpected Redis client type', ['client' => get_class($client)]);

                return 1;
            }

            // Get connection parameters and create new client with infinite timeout
            // We need read_write_timeout = 0 for pub/sub to avoid timeouts while waiting for messages
            /** @var NodeConnectionInterface $nodeConnection */
            $nodeConnection = $client->getConnection();
            $params = $nodeConnection->getParameters();

            $pubsubClient = new \Predis\Client([
                'scheme'             => $params->scheme ?? 'tcp',
                'host'               => $params->host ?? 'localhost',
                'port'               => $params->port ?? 6379,
                'database'           => $params->database ?? 0,
                'read_write_timeout' => 0, // Infinite timeout for pub/sub
            ]);

            $this->subscribePredis($pubsubClient, $podId);

            // If we reach here, subscription ended (connection died)
            $this->removeLockFile();
        } catch (\Exception $e) {
            $this->removeLockFile();
            $this->error("[Cache Subscriber] Connection failed: {$e->getMessage()}");
            $this->logger->error('[Cache Subscriber] Redis connection failed', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
                'pod'       => $podId,
            ]);
            $this->error('[Cache Subscriber] Will retry on next cron trigger');

            return 1;
        }

        return 0;
    }

    /**
     * Subscribe using Predis client.
     */
    private function subscribePredis(\Predis\Client $client, string $podId): void
    {
        $channel = (string) $this->input->getOption('channel');

        $this->info('[Cache Subscriber] ✓ Connected (Predis)');
        $this->logger->info('[Cache Subscriber] Connected', ['client' => 'Predis', 'pod' => $podId]);

        try {
            $loop = $client->pubSubLoop();
            $loop->subscribe($channel);

            $this->info("[Cache Subscriber] ✓ Subscribed to channel: {$channel}");
            $this->logger->info('[Cache Subscriber] Subscribed successfully', ['pod' => $podId, 'channel' => $channel]);
            $this->info("[Cache Subscriber] Running on pod: {$podId}");
            $this->logger->info('[Cache Subscriber] Running', ['pod' => $podId]);

            foreach ($loop as $message) {
                /** @var object{kind: string, channel: string, payload: string} $message */
                if ($message->kind === 'message') {
                    $shouldExit = $this->handleMessage($message->payload, $podId);

                    if ($shouldExit) {
                        $loop->unsubscribe();
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->error("[Cache Subscriber] Subscription error: {$e->getMessage()}");
            $this->logger->error('[Cache Subscriber] Subscription failed', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
                'pod'       => $podId,
            ]);

            throw $e;
        }
    }

    /**
     * Handle incoming cache invalidation message.
     *
     * @return bool True if should exit (--once mode), false to continue
     */
    private function handleMessage(string $message, string $podId): bool
    {
        try {
            $data = json_decode($message, true);

            if (!$data || !isset($data['source'])) {
                $this->error('[Cache Subscriber] Invalid message format, skipping');
                $this->logger->warning('[Cache Subscriber] Invalid message format received');

                return false;
            }

            // If we published this event, still run local invalidation for consistency
            if ($data['source'] === $podId) {
                $this->info('[Cache Subscriber] Source is this pod - running local invalidation');
                $this->logger->info('[Cache Subscriber] Source is this pod - running local invalidation', [
                    'source' => $data['source'],
                    'pod'    => $podId,
                ]);
            }

            $this->info("[Cache Subscriber] ⚠ Cache invalidation from: {$data['source']}");
            $this->logger->info('[Cache Subscriber] Received cache invalidation', [
                'source' => $data['source'],
                'pod'    => $podId,
            ]);

            // Invalidate local caches
            $this->invalidateLocalCaches();

            $this->info('[Cache Subscriber] ✓ Local caches cleared');
            $this->logger->info('[Cache Subscriber] Local caches cleared successfully', ['pod' => $podId]);

            // Exit if --once flag is set (for testing)
            return (bool) $this->input->getOption('once');
        } catch (\Exception $e) {
            $this->error("[Cache Subscriber] Error handling message: {$e->getMessage()}");
            $this->logger->error('[Cache Subscriber] Error handling message', [
                'exception' => $e->getMessage(),
                'pod'       => $podId,
            ]);

            return false;
        }
    }

    /**
     * Invalidate local file caches and in-memory translator catalogues.
     *
     * This replicates the legacy distributed cache invalidation logic.
     */
    private function invalidateLocalCaches(): void
    {
        try {
            // CRITICAL: Flush FileStore cache FIRST before deleting files
            // This prevents __PHP_Incomplete_Class__ errors with TextFormatter
            // The FileStore contains serialized formatter objects that reference
            // class files in storage/formatter/. We must clear the serialized cache
            // before deleting the class files, otherwise unserialization fails.
            (new Repository(resolve('cache.filestore')))->flush();

            // Clear file caches (suppress warnings if files don't exist)
            @array_map('unlink', glob($this->paths->storage.'/formatter/*') ?: []);
            @array_map('unlink', glob($this->paths->storage.'/locale/*') ?: []);
            @array_map('unlink', glob($this->paths->storage.'/views/*') ?: []);

            // Clear in-memory Symfony translator catalogues
            // This is crucial because Symfony caches translations in protected $catalogues array
            $this->locales->clearCache();

            // Clear OPcache if available
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
        } catch (\Exception $e) {
            // Fail gracefully - log but don't break the subscriber
            $this->error("[Cache Subscriber] Failed to invalidate caches: {$e->getMessage()}");
            $this->logger->error('[Cache Subscriber] Failed to invalidate local caches', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if cache:subscribe is already running in this pod.
     */
    private function isAlreadyRunning(): bool
    {
        $lockFile = $this->getLockFilePath();

        if (!file_exists($lockFile)) {
            return false;
        }

        $pid = (int) file_get_contents($lockFile);

        // Check if the process is still running
        if ($pid > 0 && $this->isProcessRunning($pid)) {
            return true;
        }

        // Stale lock file, clean it up
        $this->removeLockFile();

        return false;
    }

    /**
     * Create lock file with current PID.
     */
    private function createLockFile(): void
    {
        $lockFile = $this->getLockFilePath();
        file_put_contents($lockFile, getmypid());
    }

    /**
     * Remove lock file.
     */
    private function removeLockFile(): void
    {
        $lockFile = $this->getLockFilePath();
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    /**
     * Get the lock file path (in base directory, not shared storage).
     */
    private function getLockFilePath(): string
    {
        return $this->paths->base.'/cache-subscriber.lock';
    }

    /**
     * Check if a process is running.
     */
    private function isProcessRunning(int $pid): bool
    {
        // /proc/$pid is readable regardless of process owner (Linux).
        // posix_kill($pid, 0) returns false for cross-user processes when
        // the caller lacks permission, causing false negatives.
        if (file_exists("/proc/$pid")) {
            return true;
        }

        // Fallback for non-Linux (macOS, BSD) where /proc is absent.
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        return false;
    }
}

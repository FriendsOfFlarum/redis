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
use FoF\Redis\Cache\LocalCacheInvalidator;
use FoF\Redis\Configuration;
use FoF\Redis\Overrides\RedisManager;
use Illuminate\Support\Arr;
use Predis\Connection\NodeConnectionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputOption;

class CacheSubscribeCommand extends AbstractCommand
{
    /**
     * The well-known default channel, used only when neither --channel nor a
     * configured pubsub.channel is present. Kept in sync with the publisher's
     * default in the Cache provider.
     */
    public const DEFAULT_CHANNEL = 'flarum:cache:invalidate';

    protected Paths $paths;
    protected LoggerInterface $logger;
    protected Configuration $configuration;

    public function __construct(
        Paths $paths,
        LoggerInterface $logger,
        Configuration $configuration
    ) {
        $this->paths = $paths;
        $this->logger = $logger;
        $this->configuration = $configuration;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('cache:subscribe')
            ->setDescription('Subscribe to Redis for distributed cache invalidation')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one message and exit (for testing)')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Delay in seconds before subscribing', 0)
            // Default is null so an omitted option falls back to the CONFIGURED
            // channel (see resolveChannel), not a hardcoded literal that could
            // diverge from what the publisher uses.
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Redis pub/sub channel', null);
    }

    /**
     * Resolve the channel to subscribe on.
     *
     * Precedence: an explicit --channel wins; otherwise the configured
     * pubsub.channel (the same value the publisher uses); otherwise the
     * well-known default. This ensures a subscriber launched without --channel
     * (e.g. from cron/systemd) listens on the same channel the publisher
     * publishes to, rather than a hardcoded literal that ignores config.
     */
    public function resolveChannel(?string $option): string
    {
        if (!empty($option)) {
            return $option;
        }

        $configured = Arr::get($this->configuration->toArray(), 'pubsub.channel');

        return !empty($configured) ? $configured : self::DEFAULT_CHANNEL;
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

            if ($client instanceof \Redis) {
                // phpredis: set infinite read timeout so pub/sub never times out waiting for messages
                $client->setOption(\Redis::OPT_READ_TIMEOUT, -1);
                $this->subscribePhpRedis($client, $podId);
            } elseif ($client instanceof \Predis\Client) {
                // Predis: create a new client with read_write_timeout=0 for the same reason
                // Use all original connection parameters (handles tcp, unix sockets, tls, etc.)
                /** @var NodeConnectionInterface $nodeConnection */
                $nodeConnection = $client->getConnection();
                $params = $nodeConnection->getParameters();
                $pubsubClient = new \Predis\Client(
                    array_merge($params->toArray(), ['read_write_timeout' => 0])
                );
                $this->subscribePredis($pubsubClient, $podId);
            } else {
                $this->error('[Cache Subscriber] Unsupported Redis client: '.get_class($client));
                $this->logger->error('[Cache Subscriber] Unsupported Redis client type', ['client' => get_class($client)]);

                return 1;
            }

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
        $channel = $this->resolveChannel($this->input->getOption('channel'));

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
     * Subscribe using phpredis client.
     *
     * phpredis subscribe() is blocking and invokes the callback for each message.
     * To support --once, we close the connection from within the callback.
     */
    private function subscribePhpRedis(\Redis $client, string $podId): void
    {
        $channel = $this->resolveChannel($this->input->getOption('channel'));

        $this->info('[Cache Subscriber] ✓ Connected (phpredis)');
        $this->logger->info('[Cache Subscriber] Connected', ['client' => 'phpredis', 'pod' => $podId]);

        $this->info("[Cache Subscriber] ✓ Subscribed to channel: {$channel}");
        $this->logger->info('[Cache Subscriber] Subscribed successfully', ['pod' => $podId, 'channel' => $channel]);
        $this->info("[Cache Subscriber] Running on pod: {$podId}");
        $this->logger->info('[Cache Subscriber] Running', ['pod' => $podId]);

        try {
            $client->subscribe([$channel], function (\Redis $redis, string $chan, string $payload) use ($podId) {
                $shouldExit = $this->handleMessage($payload, $podId);

                if ($shouldExit) {
                    // phpredis has no clean unsubscribe from within the callback;
                    // closing the connection is the standard approach.
                    $redis->close();
                }
            });
        } catch (\Exception $e) {
            // A closed connection throws; if it was intentional (--once) that's fine.
            $this->logger->info('[Cache Subscriber] Subscription ended', ['pod' => $podId, 'reason' => $e->getMessage()]);
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
            $this->invalidateLocalCaches((int) ($data['version'] ?? 0));

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
     * Records the applied epoch so the request middleware does not re-apply
     * the same invalidation.
     */
    private function invalidateLocalCaches(int $version = 0): void
    {
        try {
            /** @var LocalCacheInvalidator $invalidator */
            $invalidator = resolve(LocalCacheInvalidator::class);
            $invalidator->invalidate();

            if ($version > 0) {
                $invalidator->recordApplied($version);
            }
        } catch (\Throwable $e) {
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

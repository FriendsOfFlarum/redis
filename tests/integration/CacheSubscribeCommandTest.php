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

use Flarum\Testing\integration\ConsoleTestCase;
use FoF\Redis\Console\CacheSubscribeCommand;
use PHPUnit\Framework\Attributes\Test;

class CacheSubscribeCommandTest extends ConsoleTestCase
{
    use RedisTestConfig;

    protected function tearDown(): void
    {
        $this->flushTestDatabases();

        parent::tearDown();
    }

    protected function command(): CacheSubscribeCommand
    {
        return $this->app()->getContainer()->make(CacheSubscribeCommand::class);
    }

    #[Test]
    public function without_an_explicit_channel_it_uses_the_configured_pubsub_channel()
    {
        // The publisher (Cache provider) publishes on the configured
        // `pubsub.channel`. If the subscriber is launched without --channel
        // (e.g. a cron/systemd unit running `flarum cache:subscribe`), it must
        // fall back to that SAME configured channel — not a hardcoded literal —
        // or it listens on a different channel and silently receives nothing.
        $config = $this->redisConfig();
        $config['pubsub'] = ['enabled' => true, 'channel' => 'myapp:custom:invalidate'];
        $this->registerRedis();
        // Re-register with the custom channel (registerRedis merges queue config
        // only, so set the whole config here).
        $this->extend(
            (new \FoF\Redis\Extend\Redis($config))
                ->useDatabaseWith('cache', $this->testCacheDb)
                ->useDatabaseWith('queue', $this->testQueueDb)
                ->useDatabaseWith('session', $this->testSessionDb)
                ->useDatabaseWith('settings', $this->testSettingsDb)
        );

        $channel = $this->command()->resolveChannel(null);

        $this->assertSame('myapp:custom:invalidate', $channel);
    }

    #[Test]
    public function an_explicit_channel_option_wins_over_config()
    {
        $config = $this->redisConfig();
        $config['pubsub'] = ['enabled' => true, 'channel' => 'myapp:custom:invalidate'];
        $this->extend(
            (new \FoF\Redis\Extend\Redis($config))
                ->useDatabaseWith('cache', $this->testCacheDb)
                ->useDatabaseWith('queue', $this->testQueueDb)
                ->useDatabaseWith('session', $this->testSessionDb)
                ->useDatabaseWith('settings', $this->testSettingsDb)
        );

        $this->assertSame('explicit:channel', $this->command()->resolveChannel('explicit:channel'));
    }

    #[Test]
    public function it_falls_back_to_the_default_channel_when_none_is_configured()
    {
        // No pubsub.channel configured → the well-known default.
        $this->registerRedis();

        $this->assertSame('flarum:cache:invalidate', $this->command()->resolveChannel(null));
    }
}

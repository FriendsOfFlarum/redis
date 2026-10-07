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

use Exception;
use FoF\Redis\Traits\RetrievesRedisInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RetrievesRedisInfoTest extends TestCase
{
    /**
     * A stand-in for RedisManager that only reports which connections exist.
     * The trait must pick a registered name without opening a socket.
     */
    protected function managerWith(array $registered): object
    {
        return new class($registered) {
            public function __construct(private array $registered)
            {
            }

            public function getConnectionConfig(string $name = 'default'): ?array
            {
                return in_array($name, $this->registered, true) ? ['host' => 'redis'] : null;
            }
        };
    }

    protected function consumer(array $registered): object
    {
        return new class($this->managerWith($registered)) {
            use RetrievesRedisInfo;

            public function __construct(public $redis)
            {
            }

            public function resolved(): ?string
            {
                return $this->resolveConnectionName();
            }
        };
    }

    #[Test]
    public function it_resolves_the_cache_connection_on_a_cache_only_install()
    {
        // Only the queue service registers a 'default' connection. A bare
        // connection() call resolves to 'default' and therefore blew up on an
        // install that enabled cache but not queue — the exact shape of a
        // "redis without horizon" forum.
        $this->assertSame('fof.cache', $this->consumer(['fof.cache'])->resolved());
    }

    #[Test]
    public function it_resolves_the_session_connection_when_only_sessions_are_enabled()
    {
        $this->assertSame('fof.sessions', $this->consumer(['fof.sessions'])->resolved());
    }

    #[Test]
    public function it_resolves_the_settings_connection_when_only_settings_are_enabled()
    {
        $this->assertSame('fof.settings', $this->consumer(['fof.settings'])->resolved());
    }

    #[Test]
    public function it_prefers_the_cache_connection_when_several_are_registered()
    {
        $resolved = $this->consumer(['default', 'fof.cache', 'fof.sessions'])->resolved();

        $this->assertSame('fof.cache', $resolved);
    }

    #[Test]
    public function it_falls_back_to_default_when_only_the_queue_is_enabled()
    {
        $this->assertSame('default', $this->consumer(['default'])->resolved());
    }

    #[Test]
    public function it_returns_null_when_no_connection_is_registered()
    {
        $this->assertNull($this->consumer([])->resolved());
    }

    #[Test]
    public function get_info_reports_an_error_rather_than_throwing_when_nothing_is_registered()
    {
        $consumer = new class($this->managerWith([])) {
            use RetrievesRedisInfo;

            public function __construct(public $redis)
            {
            }

            public function info(): array
            {
                return $this->getInfo();
            }
        };

        $info = $consumer->info();

        $this->assertArrayHasKey('error', $info);
    }

    /**
     * A consumer whose only registered connection answers INFO with
     * `$reply`, or throws it. Every call's arguments are kept in `$calls`.
     */
    protected function serverInfoConsumer(array|Exception $reply): object
    {
        $connection = new class($reply) {
            public array $calls = [];

            public function __construct(private array|Exception $reply)
            {
            }

            public function info(...$args): array
            {
                $this->calls[] = $args;

                if ($this->reply instanceof Exception) {
                    throw $this->reply;
                }

                return $this->reply;
            }
        };

        $manager = new class($connection) {
            public function __construct(public object $connection)
            {
            }

            public function getConnectionConfig(string $name = 'default'): ?array
            {
                return $name === 'fof.cache' ? ['host' => 'redis'] : null;
            }

            public function connection(string $name): object
            {
                return $this->connection;
            }
        };

        return new class($manager) {
            use RetrievesRedisInfo;

            public function __construct(public $redis)
            {
            }

            public function server(): array
            {
                return $this->getServerInfo();
            }
        };
    }

    #[Test]
    public function server_info_asks_for_the_server_section_alone()
    {
        // Every admin page load reads this, for the store's name and version.
        // A full INFO on phpredis is eight round trips.
        $consumer = $this->serverInfoConsumer(['redis_version' => '7.2.4']);

        $this->assertSame(['redis_version' => '7.2.4'], $consumer->server());
        $this->assertSame([['server']], $consumer->redis->connection->calls);
    }

    #[Test]
    public function server_info_unwraps_the_section_predis_nests_it_under()
    {
        $consumer = $this->serverInfoConsumer(['Server' => ['valkey_version' => '9.0.1', 'redis_version' => '7.2.4']]);

        $this->assertSame(['valkey_version' => '9.0.1', 'redis_version' => '7.2.4'], $consumer->server());
    }

    #[Test]
    public function server_info_reports_a_failed_connection_rather_than_throwing()
    {
        $info = $this->serverInfoConsumer(new Exception('Connection refused'))->server();

        $this->assertSame('Redis connection failed: Connection refused', $info['error'] ?? null);
    }

    #[Test]
    public function server_info_reports_an_error_when_nothing_is_registered()
    {
        $consumer = new class($this->managerWith([])) {
            use RetrievesRedisInfo;

            public function __construct(public $redis)
            {
            }

            public function server(): array
            {
                return $this->getServerInfo();
            }
        };

        $this->assertArrayHasKey('error', $consumer->server());
    }
}

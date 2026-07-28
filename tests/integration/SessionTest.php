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

use Flarum\Testing\integration\TestCase;
use FoF\Redis\Session\RedisSessionHandler;
use PHPUnit\Framework\Attributes\Test;
use SessionHandlerInterface;

class SessionTest extends TestCase
{
    use RedisTestConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushTestDatabases();
        $this->registerRedis();
    }

    protected function tearDown(): void
    {
        $this->flushTestDatabases();

        parent::tearDown();
    }

    protected function handler(): SessionHandlerInterface
    {
        return $this->app()->getContainer()->make(SessionHandlerInterface::class);
    }

    #[Test]
    public function the_session_handler_is_the_redis_handler()
    {
        $this->assertInstanceOf(RedisSessionHandler::class, $this->handler());
    }

    #[Test]
    public function session_data_round_trips_through_redis()
    {
        $handler = $this->handler();
        $id = 'fof-redis-session-'.bin2hex(random_bytes(8));

        $this->assertTrue($handler->write($id, 'the-session-payload'));
        $this->assertSame('the-session-payload', $handler->read($id));

        $this->assertTrue($handler->destroy($id));
        // A read after destroy returns an empty string (the SessionHandler
        // contract), never the old payload.
        $this->assertSame('', $handler->read($id));
    }

    #[Test]
    public function session_data_lands_in_the_session_redis_database()
    {
        $handler = $this->handler();
        $id = 'fof-redis-session-raw-'.bin2hex(random_bytes(8));
        $handler->write($id, 'stored-in-redis');

        $raw = $this->rawRedis($this->testSessionDb);
        $keys = $raw->keys('*'.$id.'*');

        $this->assertNotEmpty($keys, 'session data should be stored in the Redis session database');
    }

    #[Test]
    public function the_handler_survives_serialization()
    {
        // The handler holds a cache repository that is not serialisable, so it
        // implements __sleep/__wakeup to drop and re-resolve it. A worker that
        // serialises the session handler (e.g. across a queue boundary) must
        // get a working handler back.
        $handler = $this->handler();

        $restored = unserialize(serialize($handler));

        $this->assertInstanceOf(RedisSessionHandler::class, $restored);

        // And it still functions after the round-trip.
        $id = 'fof-redis-session-wake-'.bin2hex(random_bytes(8));
        $this->assertTrue($restored->write($id, 'after-wakeup'));
        $this->assertSame('after-wakeup', $restored->read($id));
    }
}

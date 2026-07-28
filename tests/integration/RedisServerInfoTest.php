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
use FoF\Redis\RedisServerInfo;
use PHPUnit\Framework\Attributes\Test;

class RedisServerInfoTest extends TestCase
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

    protected function info(): RedisServerInfo
    {
        return $this->app()->getContainer()->make(RedisServerInfo::class);
    }

    #[Test]
    public function it_is_bound_as_a_singleton()
    {
        $this->assertInstanceOf(RedisServerInfo::class, $this->info());
        $this->assertSame($this->info(), $this->info());
    }

    #[Test]
    public function it_reads_the_server_section_without_error()
    {
        $info = $this->info();

        $this->assertNotEmpty($info->section());
        $this->assertFalse($info->hasError());
        $this->assertNull($info->error());
    }

    #[Test]
    public function it_reports_a_server_name_and_version()
    {
        $info = $this->info();

        $this->assertContains($info->serverName(), ['Redis', 'Valkey']);
        // A reachable server always yields a concrete version, never 'unknown'.
        $this->assertNotSame('unknown', $info->version());
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $info->version());
    }

    #[Test]
    public function server_name_is_consistent_with_is_valkey()
    {
        $info = $this->info();

        $this->assertSame($info->isValkey() ? 'Valkey' : 'Redis', $info->serverName());
    }

    #[Test]
    public function it_reports_the_server_mode()
    {
        // Defaults to 'standalone' for a plain single-node server.
        $this->assertSame('standalone', $this->info()->mode());
    }

    #[Test]
    public function it_records_an_error_for_an_unreachable_server()
    {
        // Point at a port nothing is listening on; the INFO fetch must fail
        // gracefully — hasError() true, section() empty, no exception thrown.
        $manager = $this->app()->getContainer()->make(\FoF\Redis\Overrides\RedisManager::class);
        $manager->addConnection('fof.unreachable', [
            'host'     => '127.0.0.1',
            'port'     => 6399, // no server here
            'database' => 0,
        ]);

        $info = new RedisServerInfo($manager, 'fof.unreachable');

        $this->assertSame([], $info->section());
        $this->assertTrue($info->hasError());
        $this->assertNotNull($info->error());
        // Derived getters degrade gracefully rather than throwing.
        $this->assertSame('unknown', $info->version());
        $this->assertSame('standalone', $info->mode());
    }
}

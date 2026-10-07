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
use FoF\Redis\Api\Stats;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Test;

class StatsTest extends TestCase
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

    protected function handler(): Stats
    {
        return $this->app()->getContainer()->make(Stats::class);
    }

    protected function payload(): array
    {
        $response = $this->handler()->handle(new ServerRequest());

        $this->assertEquals(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    #[Test]
    public function it_is_resolvable_from_the_container()
    {
        $this->assertInstanceOf(Stats::class, $this->handler());
    }

    #[Test]
    public function it_returns_the_fields_the_dashboard_widget_renders()
    {
        $redis = $this->payload()['redis'];

        // The widget reads each of these by name; a rename here is a silently
        // blank tile, so pin the contract rather than just "some data came back".
        foreach ([
            'memory_used', 'memory_used_bytes', 'memory_peak', 'memory_max',
            'memory_max_bytes', 'memory_percentage', 'eviction_policy',
            'ops_per_sec', 'connected_clients', 'blocked_clients',
        ] as $key) {
            $this->assertArrayHasKey($key, $redis, "missing widget field: $key");
        }
    }

    #[Test]
    public function it_reports_a_real_server_reading()
    {
        $redis = $this->payload()['redis'];

        $this->assertGreaterThan(0, $redis['memory_used_bytes']);
        $this->assertIsString($redis['eviction_policy']);
        $this->assertGreaterThanOrEqual(1, $redis['connected_clients']);
    }

    #[Test]
    public function memory_percentage_is_null_when_no_maxmemory_is_set()
    {
        $redis = $this->payload()['redis'];

        // maxmemory of 0 means unbounded: a percentage would be a division by
        // zero, and the widget's pressure warning keys off null to stay silent.
        if ($redis['memory_max_bytes'] == 0) {
            $this->assertNull($redis['memory_percentage']);
            $this->assertSame('auto', $redis['memory_max']);
        } else {
            $this->assertIsNumeric($redis['memory_percentage']);
        }
    }

    #[Test]
    public function it_carries_a_timestamp()
    {
        $this->assertArrayHasKey('timestamp', $this->payload());
    }
}

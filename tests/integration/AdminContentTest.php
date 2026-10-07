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

use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The admin page tells the status widget and the dashboard card which store
 * is running, and which version: "Valkey 9.0.1", not just "Redis".
 */
class AdminContentTest extends TestCase
{
    use RedisTestConfig;
    use RetrievesAuthorizedUsers;

    protected function tearDown(): void
    {
        $this->flushTestDatabases();

        parent::tearDown();
    }

    protected function adminPayload(): array
    {
        $response = $this->send($this->request('GET', '/admin', ['authenticatedAs' => 1]));

        $this->assertEquals(200, $response->getStatusCode());

        preg_match('#<script id="flarum-json-payload" type="application/json">(.*?)</script>#s', (string) $response->getBody(), $matches);

        return json_decode($matches[1] ?? '{}', true);
    }

    #[Test]
    public function it_names_the_store_and_its_version()
    {
        $this->registerRedis();

        $payload = $this->adminPayload();

        $this->assertContains($payload['cacheStore'] ?? null, ['Redis', 'Valkey']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $payload['cacheVersion'] ?? '');
    }
}

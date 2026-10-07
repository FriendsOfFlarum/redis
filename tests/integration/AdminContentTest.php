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

use Flarum\Frontend\Document;
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Content\AdminContent;
use PHPUnit\Framework\Attributes\Test;

/**
 * The admin page tells the status widget and the dashboard card which store
 * is running, and which version: "Valkey 9.0.1", not just "Redis".
 */
class AdminContentTest extends TestCase
{
    use RedisTestConfig;

    protected function tearDown(): void
    {
        $this->flushTestDatabases();

        parent::tearDown();
    }

    /**
     * What AdminContent adds to the admin page's payload. Called directly:
     * rendering the page would compile the admin JS, and `js/dist` isn't built
     * until after a change is merged.
     */
    protected function adminPayload(): array
    {
        $document = $this->createStub(Document::class);

        $this->app()->getContainer()->make(AdminContent::class)($document, $this->request('GET', '/admin'));

        return $document->payload;
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

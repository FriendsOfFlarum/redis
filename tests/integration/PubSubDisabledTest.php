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
use FoF\Redis\Extend\Redis as RedisExtender;
use FoF\Redis\Middleware\DistributedCacheInvalidation;

/**
 * Separate test class: the epoch middleware must NOT be registered when
 * pub/sub is disabled. This needs an app whose ONLY Redis registration has
 * pubsub disabled, which the shared setUp in PubSubCacheInvalidationTest
 * (pubsub enabled) would contaminate.
 */
class PubSubDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extend(
            new RedisExtender([
                'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
                'password' => getenv('REDIS_PASSWORD') ?: null,
                'port'     => getenv('REDIS_PORT') ?: 6379,
                'database' => 15,
                'pubsub'   => [
                    'enabled'   => false,
                    'autostart' => false,
                ],
            ])
        );
    }

    /**
     * @test
     */
    public function middleware_is_not_registered_when_pubsub_is_disabled()
    {
        $container = $this->app()->getContainer();

        foreach (['forum', 'admin', 'api'] as $frontend) {
            $this->assertNotContains(
                DistributedCacheInvalidation::class,
                $container->make("flarum.{$frontend}.middleware"),
                "Middleware must not be registered in the {$frontend} stack when pubsub is disabled"
            );
        }
    }
}

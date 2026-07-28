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

use FoF\Redis\Configuration;
use FoF\Redis\Provides\Cache;
use FoF\Redis\Provides\Queue;
use FoF\Redis\Provides\Session;
use FoF\Redis\Provides\Settings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ConfigurationTest extends TestCase
{
    private function baseConfig(): array
    {
        return [
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => null,
            'database' => 1,
        ];
    }

    #[Test]
    public function make_accepts_an_array()
    {
        $config = Configuration::make($this->baseConfig());

        $this->assertSame('127.0.0.1', $config->toArray()['host']);
    }

    #[Test]
    public function make_rejects_a_non_existent_file_path()
    {
        $this->expectException(InvalidArgumentException::class);

        Configuration::make('/does/not/exist.php');
    }

    #[Test]
    public function for_falls_back_to_the_base_database_when_not_overridden()
    {
        $config = Configuration::make($this->baseConfig());

        $this->assertSame(1, $config->for('cache')->toArray()['database']);
    }

    #[Test]
    public function use_database_with_overrides_the_database_per_service()
    {
        $config = Configuration::make($this->baseConfig())
            ->useDatabaseWith('cache', 2)
            ->useDatabaseWith('queue', 3);

        $this->assertSame(2, $config->for('cache')->toArray()['database']);
        $this->assertSame(3, $config->for('queue')->toArray()['database']);
        // A service without an override still gets the base database.
        $this->assertSame(1, $config->for('session')->toArray()['database']);
    }

    #[Test]
    public function for_uses_a_per_service_connection_block_when_present()
    {
        $config = Configuration::make([
            'connections' => [
                'cache' => ['host' => 'cache.example', 'port' => 6380, 'database' => 5],
                'queue' => ['host' => 'queue.example', 'port' => 6381, 'database' => 6],
            ],
        ]);

        $cache = $config->for('cache')->toArray();
        $this->assertSame('cache.example', $cache['host']);
        $this->assertSame(6380, $cache['port']);
        $this->assertSame(5, $cache['database']);

        $queue = $config->for('queue')->toArray();
        $this->assertSame('queue.example', $queue['host']);
    }

    #[Test]
    public function for_preserves_top_level_prefix_when_using_a_per_service_connection_block()
    {
        // Top-level keys like `prefix` (and `pubsub`) apply to all services.
        // When a per-service `connections.<service>` block is used, for() must
        // still carry those top-level keys through — otherwise every RedisStore
        // is built with an empty prefix (Cache/Session/Settings providers read
        // `prefix` off the resolved config), so keys are written unprefixed and
        // collide with any other app sharing the Redis instance.
        $config = Configuration::make([
            'prefix'      => 'myapp:',
            'pubsub'      => ['enabled' => true, 'channel' => 'myapp:invalidate'],
            'connections' => [
                'cache'   => ['host' => 'cache.example', 'database' => 5],
                'session' => ['host' => 'session.example', 'database' => 6],
            ],
        ]);

        $cache = $config->for('cache')->toArray();
        $this->assertSame('cache.example', $cache['host'], 'per-service host still applies');
        $this->assertSame('myapp:', $cache['prefix'] ?? null, 'top-level prefix must be preserved');
        $this->assertSame('myapp:invalidate', $cache['pubsub']['channel'] ?? null, 'top-level pubsub must be preserved');

        // A per-service key overrides the top-level one; absent per-service, the
        // top-level value wins.
        $session = $config->for('session')->toArray();
        $this->assertSame('myapp:', $session['prefix'] ?? null);
    }

    #[Test]
    public function a_per_service_block_can_override_a_top_level_key()
    {
        // If a service block sets its own prefix, that wins over the top-level.
        $config = Configuration::make([
            'prefix'      => 'global:',
            'connections' => [
                'cache' => ['host' => 'cache.example', 'prefix' => 'cacheonly:'],
            ],
        ]);

        $this->assertSame('cacheonly:', $config->for('cache')->toArray()['prefix']);
    }

    #[Test]
    public function use_database_with_overrides_a_per_service_connection_block_database()
    {
        $config = Configuration::make([
            'connections' => [
                'cache' => ['host' => 'cache.example', 'database' => 5],
            ],
        ])->useDatabaseWith('cache', 9);

        $this->assertSame(9, $config->for('cache')->toArray()['database']);
    }

    #[Test]
    public function for_drops_an_empty_password()
    {
        $config = Configuration::make($this->baseConfig()); // password => null

        $this->assertArrayNotHasKey('password', $config->for('cache')->toArray());
    }

    #[Test]
    public function for_keeps_a_real_password()
    {
        $config = Configuration::make(array_merge($this->baseConfig(), ['password' => 'secret']));

        $this->assertSame('secret', $config->for('cache')->toArray()['password']);
    }

    #[Test]
    public function all_services_are_enabled_by_default()
    {
        $enabled = Configuration::make($this->baseConfig())->enabled();

        $this->assertSame(Cache::class, $enabled['cache']);
        $this->assertSame(Queue::class, $enabled['queue']);
        $this->assertSame(Session::class, $enabled['session']);
        $this->assertSame(Settings::class, $enabled['settings']);
    }

    #[Test]
    public function disable_removes_services()
    {
        $enabled = Configuration::make($this->baseConfig())
            ->disable(['cache', 'session'])
            ->enabled();

        $this->assertArrayNotHasKey('cache', $enabled);
        $this->assertArrayNotHasKey('session', $enabled);
        $this->assertArrayHasKey('queue', $enabled);
        $this->assertArrayHasKey('settings', $enabled);
    }
}

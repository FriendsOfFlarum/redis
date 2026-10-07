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

use Closure;
use Flarum\Frontend\Compiler\Source\SourceCollector;
use Flarum\Http\UrlGenerator;
use Flarum\Testing\integration\TestCase;
use FoF\Redis\Extend\Redis;
use PHPUnit\Framework\Attributes\Test;

class RedisExtenderTest extends TestCase
{
    use RedisTestConfig;

    protected function tearDown(): void
    {
        $this->flushTestDatabases();

        parent::tearDown();
    }

    /**
     * What the admin frontend's `$type` sources would compile from: each
     * string's contents, and each file's path. Recorded rather than read,
     * because `js/dist` isn't built until after a change is merged.
     *
     * @return string[]
     */
    protected function adminSources(string $type): array
    {
        $collector = new class extends SourceCollector {
            /** @var string[] */
            public array $recorded = [];

            public function addFile(string $file, ?string $extensionId = null): static
            {
                $this->recorded[] = $file;

                return $this;
            }

            public function addString(Closure $callback, ?string $key = null): static
            {
                $this->recorded[] = $callback();

                return $this;
            }
        };

        foreach ($this->app()->getContainer()->make('flarum.assets.admin')->sources[$type] as $callback) {
            $callback($collector, null);
        }

        return $collector->recorded;
    }

    #[Test]
    public function it_registers_its_admin_js_under_its_own_module_name()
    {
        // fof/redis is applied from a site's extend.php, with no extension, so
        // core's Frontend extender would name its module `site-custom`: the
        // slot the site's own admin JS uses. Whichever loads second would
        // replace the other's exports, silently dropping its JS extenders.
        $this->registerRedis();

        $sources = $this->adminSources('js');

        $this->assertContains("flarum.extensions['fof-redis']=module.exports;", $sources);
        $this->assertNotContains("flarum.extensions['site-custom']=module.exports;", $sources);
    }

    #[Test]
    public function applying_the_extender_twice_registers_the_admin_assets_once()
    {
        $this->registerRedis();
        $this->registerRedis();

        $root = dirname(__DIR__, 2);

        $this->assertCount(1, array_keys($this->adminSources('js'), $root.'/js/dist/admin.js', true));
        $this->assertCount(1, array_keys($this->adminSources('css'), $root.'/resources/less/admin.less', true));
    }

    #[Test]
    public function it_registers_the_admin_stats_route()
    {
        $this->registerRedis();

        $url = $this->app()->getContainer()->make(UrlGenerator::class)
            ->to('admin')->route('fof-redis.stats');

        $this->assertStringContainsString('/redis/api/stats', $url);
    }

    #[Test]
    public function applying_the_extender_twice_does_not_throw()
    {
        // The extender is not guaranteed to be applied once: tests register it
        // per-case, and a consumer can legitimately list it twice. Routes throws
        // RuntimeException on a duplicate name, which would take down the whole
        // admin route collection — so registration must be idempotent.
        $this->registerRedis();
        $this->registerRedis();

        $url = $this->app()->getContainer()->make(UrlGenerator::class)
            ->to('admin')->route('fof-redis.stats');

        $this->assertStringContainsString('/redis/api/stats', $url);
    }

    #[Test]
    public function it_registers_nothing_when_every_service_is_disabled()
    {
        // A bare config enables all four services, so "nothing enabled" has to
        // be built by disabling each one. The extender must then stay inert —
        // no route, and so no admin frontend either.
        $this->extend(
            (new Redis($this->redisConfig()))
                ->disable(['cache', 'queue', 'session', 'settings'])
        );

        $this->expectException(\RuntimeException::class);

        $this->app()->getContainer()->make(UrlGenerator::class)
            ->to('admin')->route('fof-redis.stats');
    }
}

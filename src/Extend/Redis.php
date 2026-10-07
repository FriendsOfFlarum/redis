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

namespace FoF\Redis\Extend;

use Flarum\Extend\Console;
use Flarum\Extend\ExtenderInterface;
use Flarum\Extend\Frontend;
use Flarum\Extend\Locales;
use Flarum\Extend\Routes;
use Flarum\Extension\Extension;
use Flarum\Frontend\Assets;
use Flarum\Frontend\Compiler\Source\SourceCollector;
use FoF\Redis\Api\Stats;
use FoF\Redis\Configuration;
use FoF\Redis\Console\CacheSubscribeCommand;
use FoF\Redis\Console\RedisInfoCommand;
use FoF\Redis\Content\AdminContent;
use Illuminate\Contracts\Container\Container;

/**
 * @mixin Configuration
 */
class Redis implements ExtenderInterface
{
    protected Configuration $configuration;

    public function __construct(array $config)
    {
        $this->configuration = Configuration::make($config);
    }

    /**
     * Applied from a site's extend.php there is no extension, so core's
     * Frontend extender would name the module `site-custom`: the slot the
     * site's own admin JS uses, where whichever loads second replaces the
     * other's exports. This is core's wrapper under a name of our own.
     */
    protected function registerAdminJs(Container $container): void
    {
        $container->resolving('flarum.assets.admin', function (Assets $assets) {
            $assets->js(function (SourceCollector $sources) {
                $sources->addString(fn () => 'var module={};');
                $sources->addFile(dirname(__DIR__, 2).'/js/dist/admin.js');
                $sources->addString(fn () => "flarum.extensions['fof-redis']=module.exports;");
            });
        });
    }

    public function extend(Container $container, ?Extension $extension = null): void
    {
        $services = $this->configuration->enabled();

        // Add bindings only if any of the redis services are requested.
        if (count($services)) {
            $container->instance(Configuration::class, $this->configuration);

            (new Bindings())->extend($container, $extension);

            (new Console())
                ->command(RedisInfoCommand::class)
                ->extend($container, $extension);

            // Once per container: the extender may be applied more than once
            // (a consumer listing it twice, or a test re-registering it with
            // different config). Each application would add the admin assets
            // again, and RouteCollection::addRoute() throws on a duplicate name.
            if (!$container->bound('fof.redis.admin')) {
                $container->instance('fof.redis.admin', true);

                $this->registerAdminJs($container);

                (new Frontend('admin'))
                    ->css(dirname(__DIR__, 2).'/resources/less/admin.less')
                    ->content(AdminContent::class)
                    ->extend($container, $extension);

                (new Locales(dirname(__DIR__, 2).'/resources/locale'))
                    ->extend($container, $extension);

                (new Routes('admin'))
                    ->get('/redis/api/stats', 'fof-redis.stats', Stats::class)
                    ->extend($container, $extension);
            }
        }

        if (array_key_exists('cache', $services)) {
            (new Console())
                ->command(CacheSubscribeCommand::class)
                ->extend($container, $extension);
        }

        foreach ($services as $service => $class) {
            (new $class())(
                $this->configuration->for($service),
                $container
            );
        }
    }

    public function __call(string $name, array $arguments): mixed
    {
        $forwarded = call_user_func_array([$this->configuration, $name], $arguments);

        // Allows chaining from extend.php so that it doesn't return the Configuration instance.
        if ($forwarded instanceof Configuration) {
            return $this;
        }

        return $forwarded;
    }
}

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
use Flarum\Extension\Extension;
use FoF\Redis\Configuration;
use FoF\Redis\Console\CacheSubscribeCommand;
use FoF\Redis\Console\RedisInfoCommand;
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

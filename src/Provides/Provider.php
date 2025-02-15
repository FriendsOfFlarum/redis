<?php

/*
 * This file is part of blomstra/flarum-redis.
 *
 * Copyright (c) Bokt.
 * Copyright (c) Blomstra Ltd.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Redis\Provides;

use FoF\Redis\Configuration;
use Illuminate\Contracts\Container\Container;

abstract class Provider
{
    abstract public function __invoke(Configuration $configuration, Container $container);
}

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

use Flarum\Foundation\Paths;
use Flarum\Locale\LocaleManager;
use FoF\Redis\Console\CacheSubscribeCommand;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CacheSubscribeCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @test
     */
    public function command_registers_expected_options()
    {
        $paths = new Paths([
            'base'    => sys_get_temp_dir().'/flarum-redis-test',
            'public'  => sys_get_temp_dir().'/flarum-redis-test-public',
            'storage' => sys_get_temp_dir().'/flarum-redis-test-storage',
        ]);

        $locales = \Mockery::mock(LocaleManager::class);
        $logger = \Mockery::mock(LoggerInterface::class);

        $command = new CacheSubscribeCommand($paths, $locales, $logger);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('once'));
        $this->assertTrue($definition->hasOption('delay'));
        $this->assertTrue($definition->hasOption('channel'));
    }
}

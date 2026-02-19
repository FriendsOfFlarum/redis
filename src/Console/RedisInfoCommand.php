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

namespace FoF\Redis\Console;

use Flarum\Console\AbstractCommand;
use FoF\Redis\Configuration;
use FoF\Redis\Overrides\RedisManager;
use FoF\Redis\RedisServerInfo;
use Illuminate\Support\Arr;
use Symfony\Component\Console\Command\Command;

class RedisInfoCommand extends AbstractCommand
{
    public function __construct(
        private readonly Configuration $configuration,
        private readonly RedisServerInfo $serverInfo
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('redis:info')
            ->setDescription('Display information about the fof/redis configuration and connection status');
    }

    protected function fire(): int
    {
        $services = $this->configuration->enabled();

        $this->output->writeln('<info>Redis services:</info> '.implode(', ', array_keys($services)));

        $this->showConnectionInfo();

        if (array_key_exists('cache', $services)) {
            $this->showCacheInfo();
        }

        return Command::SUCCESS;
    }

    private function showConnectionInfo(): void
    {
        if ($this->serverInfo->hasError()) {
            $this->output->writeln('<error>Redis connection failed: '.$this->serverInfo->error().'</error>');

            return;
        }

        $this->output->writeln('<info>'.$this->serverInfo->serverName().' version:</info> '.$this->serverInfo->version());
        $this->output->writeln('<info>'.$this->serverInfo->serverName().' mode:</info> '.$this->serverInfo->mode());
    }

    private function showCacheInfo(): void
    {
        $rawConfig = $this->configuration->for('cache')->toArray();
        $pubSubConfig = Arr::get($rawConfig, 'pubsub', []);
        $enabled = (bool) ($pubSubConfig['enabled'] ?? false);
        $autostart = array_key_exists('autostart', $pubSubConfig) ? (bool) $pubSubConfig['autostart'] : $enabled;
        $channel = $pubSubConfig['channel'] ?? 'flarum:cache:invalidate';

        $this->output->writeln('<info>Pub/sub enabled:</info> '.($enabled || $autostart ? 'yes' : 'no'));

        if (!$enabled && !$autostart) {
            return;
        }

        $this->output->writeln('<info>Pub/sub channel:</info> '.$channel);

        try {
            /** @var RedisManager $manager */
            $manager = resolve(RedisManager::class);
            $result = $manager->connection('fof.cache')->command('pubsub', ['numsub', $channel]);

            /** @var array<string, int> $numsub */
            $numsub = $result;
            $count = $numsub[$channel] ?? 0;

            $this->output->writeln('<info>Pub/sub subscribers:</info> '.$count);
        } catch (\Exception $e) {
            $this->output->writeln('<comment>Pub/sub subscriber count unavailable: '.$e->getMessage().'</comment>');
        }
    }
}

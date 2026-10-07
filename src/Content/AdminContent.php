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

namespace FoF\Redis\Content;

use Flarum\Frontend\Document;
use FoF\Redis\Overrides\RedisManager;
use FoF\Redis\Traits\RetrievesRedisInfo;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

class AdminContent
{
    use RetrievesRedisInfo;

    public function __construct(
        protected RedisManager $redis
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $cacheInfo = $this->getCacheInfo();
        $document->payload['cacheStore'] = $cacheInfo['type'];
        $document->payload['cacheVersion'] = $cacheInfo['version'];
    }

    protected function getCacheInfo(): array
    {
        $info = $this->getServerInfo();

        if (Arr::has($info, 'error')) {
            return [
                'type'    => 'error',
                'version' => Arr::get($info, 'error', 'unknown'),
            ];
        }

        // Valkey also reports a redis_version, for compatibility.
        if (Arr::has($info, 'valkey_version')) {
            return [
                'type'    => 'Valkey',
                'version' => Arr::get($info, 'valkey_version', 'unknown'),
            ];
        }

        return [
            'type'    => 'Redis',
            'version' => Arr::get($info, 'redis_version', 'unknown'),
        ];
    }
}

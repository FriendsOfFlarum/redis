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

use FoF\Redis\Provides\Cache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CacheAssetRevisionsConfigTest extends TestCase
{
    #[Test]
    public function auto_follows_pubsub()
    {
        $this->assertSame('redis', $this->normalize(null, true));
        $this->assertSame('file', $this->normalize(null, false));
        $this->assertSame('redis', $this->normalize('auto', true));
        $this->assertSame('file', $this->normalize('auto', false));
    }

    #[Test]
    public function explicit_values_win_over_pubsub()
    {
        $this->assertSame('file', $this->normalize('file', true));
        $this->assertSame('redis', $this->normalize('redis', false));
        $this->assertSame('redis', $this->normalize(' Redis ', false));
    }

    #[Test]
    public function unrecognised_values_fall_back_to_auto()
    {
        $this->assertSame('redis', $this->normalize('yes', true));
        $this->assertSame('file', $this->normalize(true, false));
        $this->assertSame('file', $this->normalize(['redis'], false));
    }

    private function normalize(mixed $value, bool $pubSubEnabled): string
    {
        $method = (new \ReflectionClass(Cache::class))->getMethod('normalizeAssetRevisions');

        return $method->invoke(new Cache(), $value, $pubSubEnabled);
    }
}

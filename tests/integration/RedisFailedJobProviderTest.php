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
use FoF\Redis\Extend\Redis;
use FoF\Redis\Queue\RedisFailedJobProvider;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use PHPUnit\Framework\Attributes\Test;

class RedisFailedJobProviderTest extends TestCase
{
    use RedisTestConfig;

    protected int $ttl = 604800;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flushTestDatabases();
        $this->registerRedis();
    }

    protected function tearDown(): void
    {
        $this->flushTestDatabases();

        parent::tearDown();
    }

    protected function failer(): RedisFailedJobProvider
    {
        return $this->app()->getContainer()->make('queue.failer');
    }

    private function payload(string $uuid, string $display = 'FoF\\Redis\\Tests\\FakeJob'): string
    {
        return json_encode(['uuid' => $uuid, 'displayName' => $display, 'job' => $display]);
    }

    #[Test]
    public function it_overrides_the_null_failer_with_a_redis_one()
    {
        $failer = $this->failer();

        $this->assertInstanceOf(RedisFailedJobProvider::class, $failer);
        $this->assertInstanceOf(FailedJobProviderInterface::class, $failer);
    }

    #[Test]
    public function it_logs_and_finds_a_failed_job_with_the_expected_shape()
    {
        $failer = $this->failer();

        $id = $failer->log('redis', 'default', $this->payload('uuid-1'), new \RuntimeException('boom'));

        $this->assertSame('uuid-1', $id);

        $job = $failer->find($id);
        $this->assertNotNull($job);
        // The management UI (Flarum\Queue\FailedJobs::all) reads exactly these.
        $this->assertSame('uuid-1', $job->id);
        $this->assertSame('redis', $job->connection);
        $this->assertSame('default', $job->queue);
        $this->assertSame($this->payload('uuid-1'), $job->payload);
        $this->assertStringContainsString('boom', $job->exception);
        $this->assertNotEmpty($job->failed_at);
    }

    #[Test]
    public function find_returns_null_for_an_unknown_id()
    {
        $this->assertNull($this->failer()->find('does-not-exist'));
    }

    #[Test]
    public function it_counts_and_lists_failed_jobs()
    {
        $failer = $this->failer();

        $this->assertSame(0, $failer->count());
        $this->assertSame([], $failer->all());
        $this->assertSame([], $failer->ids());

        $failer->log('redis', 'default', $this->payload('a'), new \RuntimeException('a'));
        $failer->log('redis', 'emails', $this->payload('b'), new \RuntimeException('b'));

        $this->assertSame(2, $failer->count());
        $this->assertCount(2, $failer->all());
        $this->assertEqualsCanonicalizing(['a', 'b'], $failer->ids());
    }

    #[Test]
    public function ids_and_count_can_filter_by_queue()
    {
        $failer = $this->failer();

        $failer->log('redis', 'default', $this->payload('a'), new \RuntimeException('a'));
        $failer->log('redis', 'emails', $this->payload('b'), new \RuntimeException('b'));
        $failer->log('redis', 'emails', $this->payload('c'), new \RuntimeException('c'));

        $this->assertEqualsCanonicalizing(['b', 'c'], $failer->ids('emails'));
        $this->assertSame(2, $failer->count(null, 'emails'));
        $this->assertSame(1, $failer->count(null, 'default'));
    }

    #[Test]
    public function all_returns_newest_first()
    {
        $failer = $this->failer();

        // Force distinct, ascending timestamps.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now());
        $failer->log('redis', 'default', $this->payload('older'), new \RuntimeException('1'));
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds(10));
        $failer->log('redis', 'default', $this->payload('newer'), new \RuntimeException('2'));
        \Illuminate\Support\Carbon::setTestNow();

        $ids = array_map(fn ($j) => $j->id, $failer->all());
        $this->assertSame(['newer', 'older'], $ids);
    }

    #[Test]
    public function forget_removes_a_single_job()
    {
        $failer = $this->failer();

        $failer->log('redis', 'default', $this->payload('keep'), new \RuntimeException('k'));
        $failer->log('redis', 'default', $this->payload('drop'), new \RuntimeException('d'));

        $this->assertTrue($failer->forget('drop'));
        $this->assertFalse($failer->forget('drop'), 'forgetting a gone id returns false');

        $this->assertSame(1, $failer->count());
        $this->assertNull($failer->find('drop'));
        $this->assertNotNull($failer->find('keep'));
    }

    #[Test]
    public function flush_without_argument_clears_everything()
    {
        $failer = $this->failer();

        $failer->log('redis', 'default', $this->payload('a'), new \RuntimeException('a'));
        $failer->log('redis', 'default', $this->payload('b'), new \RuntimeException('b'));

        $failer->flush();

        $this->assertSame(0, $failer->count());
        $this->assertSame([], $failer->all());
    }

    #[Test]
    public function flush_with_hours_only_prunes_old_jobs()
    {
        $failer = $this->failer();

        // Old job: failed 48h ago.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->subHours(48));
        $failer->log('redis', 'default', $this->payload('old'), new \RuntimeException('old'));
        \Illuminate\Support\Carbon::setTestNow();
        // Recent job: just now.
        $failer->log('redis', 'default', $this->payload('recent'), new \RuntimeException('recent'));

        // Flush anything older than 24h.
        $failer->flush(24);

        $this->assertNull($failer->find('old'));
        $this->assertNotNull($failer->find('recent'));
        $this->assertSame(1, $failer->count());
    }

    #[Test]
    public function prune_removes_jobs_before_a_date_and_returns_the_count()
    {
        $failer = $this->failer();

        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->subDays(10));
        $failer->log('redis', 'default', $this->payload('x'), new \RuntimeException('x'));
        $failer->log('redis', 'default', $this->payload('y'), new \RuntimeException('y'));
        \Illuminate\Support\Carbon::setTestNow();
        $failer->log('redis', 'default', $this->payload('z'), new \RuntimeException('z'));

        $pruned = $failer->prune(\Illuminate\Support\Carbon::now()->subDays(1));

        $this->assertSame(2, $pruned);
        $this->assertSame(1, $failer->count());
        $this->assertNotNull($failer->find('z'));
    }

    #[Test]
    public function it_applies_the_configured_ttl_to_stored_jobs()
    {
        $failer = $this->failer();
        $id = $failer->log('redis', 'default', $this->payload('ttl'), new \RuntimeException('t'));

        $conn = $this->app()->getContainer()->make(Factory::class)->connection('default');
        $ttl = (int) $conn->ttl('queues:failed:'.$id);

        // Should carry a positive TTL close to the configured 7 days (allow slack).
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual($this->ttl, $ttl);
        $this->assertGreaterThan($this->ttl - 60, $ttl);
    }

    #[Test]
    public function reads_tolerate_a_hash_that_expired_out_from_under_the_index()
    {
        $failer = $this->failer();
        $conn = $this->app()->getContainer()->make(Factory::class)->connection('default');

        $failer->log('redis', 'default', $this->payload('live'), new \RuntimeException('l'));
        $failer->log('redis', 'default', $this->payload('ghost'), new \RuntimeException('g'));

        // Simulate the ghost's hash being evicted/expired while its index entry lingers.
        $conn->del('queues:failed:ghost');

        // all()/ids()/count-via-all must skip the dangling id and self-heal the index.
        $this->assertSame(['live'], $failer->ids());
        $this->assertCount(1, $failer->all());
        // The stale index member is cleaned up by the read (zscore of a gone
        // member is null on predis / false on phpredis — never a real score).
        $this->assertEmpty($conn->zscore('queues:failed', 'ghost'));
    }
}

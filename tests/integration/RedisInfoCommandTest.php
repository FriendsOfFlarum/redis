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

use Flarum\Testing\integration\ConsoleTestCase;
use FoF\Redis\Extend\Redis;
use PHPUnit\Framework\Attributes\Test;

class RedisInfoCommandTest extends ConsoleTestCase
{
    use RedisTestConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extend(
            (new Redis($this->redisConfig()))
                ->useDatabaseWith('cache', 1)
                ->useDatabaseWith('queue', 2)
                ->useDatabaseWith('session', 3)
        );
    }

    #[Test]
    public function it_reports_the_configured_redis_services()
    {
        $output = $this->runCommand(['command' => 'redis:info']);

        $this->assertStringContainsString('Redis services:', $output);
        $this->assertStringContainsString('cache', $output);
        $this->assertStringContainsString('queue', $output);
        $this->assertStringContainsString('session', $output);
    }

    #[Test]
    public function it_reports_the_pubsub_channel()
    {
        $output = $this->runCommand(['command' => 'redis:info']);

        $this->assertStringContainsString('Pub/sub enabled:', $output);
        $this->assertStringContainsString('Pub/sub channel:', $output);
    }

    /**
     * Regression test for the PUBSUB NUMSUB subscriber-count line.
     *
     * phpredis and predis disagree on how the channel argument must be passed:
     * phpredis (\Redis) wants an array and errors with a PHP warning on a bare
     * string ("Invalid channels value"); predis wants a string and mangles an
     * array ("Array to string conversion"). The buggy single-client form
     * therefore breaks on whichever client the command was NOT written for.
     *
     * Crucially, on a fresh server there are zero subscribers, so a broken
     * count and a correct count both print "0" — the count value alone can't
     * distinguish the fix from the bug. The reliable signal is the PHP
     * warning, so this test converts any PHP warning raised during the command
     * into a test failure. It runs under both clients via the CI matrix, so it
     * fails on whichever client the command gets wrong.
     */
    #[Test]
    public function it_reports_the_pubsub_subscriber_count_without_raising_a_warning()
    {
        $warnings = [];

        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true; // swallow so it doesn't abort; we assert on it below
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_DEPRECATED);

        try {
            $output = $this->runCommand(['command' => 'redis:info']);
        } finally {
            restore_error_handler();
        }

        // No PHP warning may be raised while counting subscribers. This is what
        // fails on the buggy single-client code under the "wrong" client.
        $clientWarnings = array_filter(
            $warnings,
            fn (string $w) => str_contains($w, 'Invalid channels value')
                || str_contains($w, 'Array to string conversion')
                || str_contains($w, 'pubsub')
        );
        $this->assertSame(
            [],
            array_values($clientWarnings),
            'redis:info raised a client-specific PUBSUB warning: '.implode('; ', $clientWarnings)
        );

        // And the count line must still be present as a plain integer.
        $this->assertMatchesRegularExpression(
            '/Pub\/sub subscribers:\s+\d+/',
            $output,
            'redis:info should report a numeric subscriber count'
        );
        $this->assertStringNotContainsString('subscriber count unavailable', $output);
    }

    /**
     * With exactly one live subscriber on the channel, the reported count must
     * be at least 1 — proving the command reads NUMSUB correctly (right client
     * call AND right result shape), not merely that it avoids a warning. A
     * background PHP process holds the subscription for the duration.
     *
     * We assert ">= 1" rather than "== 1" because a shared Redis (e.g. a dev
     * box, or parallel CI on one server) may have other subscribers; the point
     * is that OUR subscriber is counted, which a broken NUMSUB read (returning
     * 0/false) would miss.
     */
    #[Test]
    public function it_counts_a_live_subscriber()
    {
        $config = $this->redisConfig();
        $channel = 'flarum:cache:invalidate';

        $subscriber = $this->spawnSubscriber($config['host'], (int) $config['port'], $channel);

        try {
            // Give the background subscriber a moment to establish before we count.
            $this->waitForSubscriber($config, $channel);

            $output = $this->runCommand(['command' => 'redis:info']);
        } finally {
            $this->stopSubscriber($subscriber);
        }

        preg_match('/Pub\/sub subscribers:\s+(\d+)/', $output, $m);
        $this->assertNotEmpty($m, "redis:info did not report a subscriber count:\n$output");
        $this->assertGreaterThanOrEqual(
            1,
            (int) $m[1],
            'redis:info should count the live subscriber, got '.$m[1]
        );
    }

    /**
     * Spawn a detached PHP process that subscribes to the channel and blocks.
     * Returns the proc-open resource + pipes for teardown.
     *
     * @return array{0: resource, 1: array}
     */
    private function spawnSubscriber(string $host, int $port, string $channel): array
    {
        // Match the extender's client selection so we exercise the same stack.
        if (extension_loaded('redis')) {
            $code = sprintf(
                '$r=new Redis();$r->connect(%s,%d);$r->setOption(Redis::OPT_READ_TIMEOUT,-1);'
                .'$r->subscribe([%s],function(){});',
                var_export($host, true),
                $port,
                var_export($channel, true)
            );
        } else {
            $code = sprintf(
                'require %s;$c=new Predis\Client(["scheme"=>"tcp","host"=>%s,"port"=>%d,"read_write_timeout"=>0]);'
                .'$ps=$c->pubSubLoop();$ps->subscribe(%s);foreach($ps as $m){}',
                var_export(getcwd().'/vendor/autoload.php', true),
                var_export($host, true),
                $port,
                var_export($channel, true)
            );
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open([PHP_BINARY, '-r', $code], $descriptors, $pipes);

        $this->assertIsResource($proc, 'failed to spawn subscriber process');

        return [$proc, $pipes];
    }

    /**
     * Poll NUMSUB directly until our subscriber appears (or time out).
     */
    private function waitForSubscriber(array $config, string $channel): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            if (extension_loaded('redis')) {
                $r = new \Redis();
                $r->connect($config['host'], (int) $config['port']);
                $res = $r->pubsub('numsub', [$channel]);
                $r->close();
            } else {
                $c = new \Predis\Client(['scheme' => 'tcp', 'host' => $config['host'], 'port' => (int) $config['port']]);
                $res = $c->pubsub('numsub', $channel);
            }

            if ((int) ($res[$channel] ?? 0) >= 1) {
                return;
            }

            usleep(100_000);
        }

        $this->fail('background subscriber never registered on the channel');
    }

    /**
     * @param array{0: resource, 1: array} $subscriber
     */
    private function stopSubscriber(array $subscriber): void
    {
        [$proc, $pipes] = $subscriber;

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($proc)) {
            proc_terminate($proc, SIGKILL);
            proc_close($proc);
        }
    }
}

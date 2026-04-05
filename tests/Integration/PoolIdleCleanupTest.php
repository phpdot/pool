<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Integration;

use PHPdot\Pool\Pool;
use PHPdot\Pool\PoolConfig;
use PHPdot\Pool\Tests\Fixtures\FakeConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class PoolIdleCleanupTest extends TestCase
{
    #[Test]
    public function it_closes_idle_connections_above_min(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 1,
                maxConnections: 5,
                maxIdleTime: 0.1, // 100ms
                idleCheckInterval: 0.1, // check every 100ms
                heartbeatInterval: 0.0,
            ));
            $pool->init();

            // Borrow 3 more to grow the pool
            $conns = [];
            for ($i = 0; $i < 3; $i++) {
                $conns[] = $pool->borrow();
            }
            foreach ($conns as $c) {
                $pool->release($c);
            }

            self::assertGreaterThanOrEqual(3, $pool->stats()->total);

            // Wait for idle cleanup to run
            Coroutine::sleep(0.4);

            // Should shrink to minConnections (1)
            $stats = $pool->stats();
            self::assertSame(1, $stats->total);
            self::assertGreaterThan(0, $connector->closeCount);

            $pool->close();
        });
    }

    #[Test]
    public function it_never_drops_below_min_connections(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 3,
                maxConnections: 5,
                maxIdleTime: 0.05,
                idleCheckInterval: 0.05,
                heartbeatInterval: 0.0,
            ));
            $pool->init();

            self::assertSame(3, $pool->stats()->total);

            // Wait for cleanup cycles
            Coroutine::sleep(0.3);

            // Should never go below 3
            self::assertGreaterThanOrEqual(3, $pool->stats()->total);

            $pool->close();
        });
    }

    #[Test]
    public function it_does_not_cleanup_when_disabled(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 1,
                maxConnections: 5,
                maxIdleTime: 0.0, // disabled
                heartbeatInterval: 0.0,
            ));
            $pool->init();

            // Grow the pool
            $conns = [];
            for ($i = 0; $i < 3; $i++) {
                $conns[] = $pool->borrow();
            }
            foreach ($conns as $c) {
                $pool->release($c);
            }

            Coroutine::sleep(0.2);

            // No cleanup — all connections should remain (1 init + 2 on-demand = 3, since first borrow uses init)
            self::assertGreaterThanOrEqual(3, $pool->stats()->total);

            $pool->close();
        });
    }
}

<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Integration;

use PHPdot\Pool\Pool;
use PHPdot\Pool\PoolConfig;
use PHPdot\Pool\Tests\Fixtures\FakeConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class PoolHeartbeatTest extends TestCase
{
    #[Test]
    public function it_does_not_call_is_alive_when_heartbeat_disabled(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 2,
                maxConnections: 5,
                heartbeatInterval: 0.0, // disabled
                maxIdleTime: 0.0,
            ));
            $pool->init();

            Coroutine::sleep(0.1);

            self::assertSame(0, $connector->isAliveCount);

            $pool->close();
        });
    }

    #[Test]
    public function it_replaces_dead_connections_on_heartbeat(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 2,
                maxConnections: 5,
                heartbeatInterval: 0.1, // 100ms
                maxIdleTime: 0.0,
            ));
            $pool->init();

            self::assertSame(2, $pool->stats()->total);

            // Mark all connections as dead
            $connector->aliveResult = false;

            // Wait for heartbeat to fire
            Coroutine::sleep(0.25);

            // Connections should be replaced — isAlive called, dead ones closed, refilled
            self::assertGreaterThan(0, $connector->isAliveCount);
            self::assertGreaterThan(0, $connector->closeCount);
            // Pool refills to minConnections
            self::assertSame(2, $pool->stats()->total);

            $pool->close();
        });
    }

    #[Test]
    public function it_keeps_alive_connections_on_heartbeat(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $connector->aliveResult = true;

            $pool = new Pool($connector, new PoolConfig(
                minConnections: 2,
                maxConnections: 5,
                heartbeatInterval: 0.1,
                maxIdleTime: 0.0,
            ));
            $pool->init();

            $initialCreateCount = $connector->createCount;

            Coroutine::sleep(0.25);

            // No replacements needed — createCount should stay the same
            self::assertSame($initialCreateCount, $connector->createCount);
            self::assertSame(0, $connector->closeCount);

            $pool->close();
        });
    }
}

<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Integration;

use PHPdot\Pool\Pool;
use PHPdot\Pool\PoolConfig;
use PHPdot\Pool\Tests\Fixtures\FakeConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class PoolValidationTest extends TestCase
{
    #[Test]
    public function it_skips_validation_on_borrow_when_idle_below_threshold(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 1,
                maxConnections: 2,
                heartbeatInterval: 0.0,
                maxIdleTime: 0.0,
                validateOnBorrowAfterIdle: 5.0,
            ));
            $pool->init();

            $conn = $pool->borrow();
            $pool->release($conn);

            // Immediate re-borrow — connection idle for ~ms, well below 5s threshold
            $pool->borrow();

            self::assertSame(0, $connector->isAliveCount);

            $pool->close();
        });
    }

    #[Test]
    public function it_validates_on_borrow_when_idle_exceeds_threshold(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 1,
                maxConnections: 2,
                heartbeatInterval: 0.0,
                maxIdleTime: 0.0,
                validateOnBorrowAfterIdle: 0.05, // 50ms gate for fast test
            ));
            $pool->init();

            $conn = $pool->borrow();
            $pool->release($conn);

            Coroutine::sleep(0.1); // exceed the 50ms gate

            $pool->borrow();

            self::assertGreaterThanOrEqual(1, $connector->isAliveCount);

            $pool->close();
        });
    }

    #[Test]
    public function it_closes_dead_connection_on_borrow_and_creates_replacement(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 1,
                maxConnections: 2,
                heartbeatInterval: 0.0,
                maxIdleTime: 0.0,
                validateOnBorrowAfterIdle: 0.0, // always validate
            ));
            $pool->init();

            $createdAtInit = $connector->createCount;

            $conn = $pool->borrow();
            $pool->release($conn);

            $connector->aliveResult = false; // next isAlive returns false

            $newConn = $pool->borrow();

            $stats = $pool->stats();
            self::assertGreaterThan($createdAtInit, $connector->createCount, 'Should have created a replacement connection');
            self::assertSame(1, $stats->active);

            $pool->close();
        });
    }

    #[Test]
    public function it_does_not_validate_on_borrow_when_disabled(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 1,
                maxConnections: 2,
                heartbeatInterval: 0.0,
                maxIdleTime: 0.0,
                validateOnBorrowAfterIdle: -1.0, // disabled
            ));
            $pool->init();

            $conn = $pool->borrow();
            $pool->release($conn);

            Coroutine::sleep(0.05);

            $pool->borrow();

            self::assertSame(0, $connector->isAliveCount);

            $pool->close();
        });
    }

    #[Test]
    public function it_validates_on_return_when_enabled(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 1,
                maxConnections: 2,
                heartbeatInterval: 0.0,
                maxIdleTime: 0.0,
                validateOnBorrowAfterIdle: -1.0, // disable on borrow to isolate
                validateOnReturn: true,
            ));
            $pool->init();

            $conn = $pool->borrow();
            $pool->release($conn);

            self::assertSame(1, $connector->isAliveCount);

            $pool->close();
        });
    }

    #[Test]
    public function dead_connection_on_release_is_closed_and_pool_refills(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 2,
                maxConnections: 5,
                heartbeatInterval: 0.0,
                maxIdleTime: 0.0,
                validateOnBorrowAfterIdle: -1.0,
                validateOnReturn: true,
            ));
            $pool->init();

            $conn = $pool->borrow();

            $connector->aliveResult = false; // next isAlive() returns false

            $pool->release($conn);

            $stats = $pool->stats();
            self::assertSame(0, $stats->active, 'no connections should be borrowed after release');
            self::assertGreaterThanOrEqual(2, $stats->total, 'pool should have refilled to minConnections');

            $pool->close();
        });
    }
}

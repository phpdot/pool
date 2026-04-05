<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Integration;

use PHPdot\Pool\Exception\BorrowTimeoutException;
use PHPdot\Pool\Exception\PoolClosedException;
use PHPdot\Pool\Pool;
use PHPdot\Pool\PoolConfig;
use PHPdot\Pool\Tests\Fixtures\FakeConnection;
use PHPdot\Pool\Tests\Fixtures\FakeConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PoolTest extends TestCase
{
    #[Test]
    public function it_initializes_with_min_connections(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 3, maxConnections: 10, maxIdleTime: 0.0));
            $pool->init();

            $stats = $pool->stats();
            self::assertSame(3, $stats->total);
            self::assertSame(3, $stats->idle);
            self::assertSame(0, $stats->active);
            self::assertSame(3, $connector->createCount);

            $pool->close();
        });
    }

    #[Test]
    public function it_borrows_and_releases(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 2, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            $conn = $pool->borrow();
            self::assertInstanceOf(FakeConnection::class, $conn);

            $stats = $pool->stats();
            self::assertSame(1, $stats->active);
            self::assertSame(1, $stats->idle);
            self::assertSame(1, $stats->borrowCount);

            $pool->release($conn);

            $stats = $pool->stats();
            self::assertSame(0, $stats->active);
            self::assertSame(2, $stats->idle);
            self::assertSame(1, $stats->releaseCount);

            $pool->close();
        });
    }

    #[Test]
    public function it_creates_on_demand_when_pool_empty(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 1, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            $conn1 = $pool->borrow(); // takes the pre-created one
            $conn2 = $pool->borrow(); // creates on demand

            self::assertSame(2, $pool->stats()->total);
            self::assertSame(2, $pool->stats()->active);
            self::assertSame(2, $connector->createCount); // 1 init + 1 on-demand

            $pool->release($conn1);
            $pool->release($conn2);
            $pool->close();
        });
    }

    #[Test]
    public function it_discards_connection(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 2, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            $conn = $pool->borrow();
            $pool->discard($conn);

            $stats = $pool->stats();
            self::assertSame(0, $stats->active);
            self::assertSame(1, $stats->total); // was 2, discarded 1
            self::assertSame(1, $stats->discardCount);
            self::assertSame(1, $connector->closeCount); // discarded connection closed

            $pool->close();
        });
    }

    #[Test]
    public function it_ignores_double_release(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 2, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            $conn = $pool->borrow();
            $pool->release($conn);
            $pool->release($conn); // double release — silently ignored

            $stats = $pool->stats();
            self::assertSame(1, $stats->releaseCount); // only counted once
            self::assertSame(2, $stats->idle);

            $pool->close();
        });
    }

    #[Test]
    public function it_ignores_double_discard(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 2, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            $conn = $pool->borrow();
            $pool->discard($conn);
            $pool->discard($conn); // double — ignored

            self::assertSame(1, $pool->stats()->discardCount);

            $pool->close();
        });
    }

    #[Test]
    public function it_throws_pool_closed_on_borrow(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 1, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            $pool->close();

            $thrown = false;

            try {
                $pool->borrow();
            } catch (PoolClosedException) {
                $thrown = true;
            }

            self::assertTrue($thrown);
        });
    }

    #[Test]
    public function it_closes_borrowed_connections_on_release_after_shutdown(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 2, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            $conn = $pool->borrow();
            $pool->close(); // close pool while connection is borrowed

            $pool->release($conn); // should close, not push to channel

            self::assertSame(0, $pool->stats()->total);
            self::assertTrue($conn instanceof FakeConnection && $conn->closed);
        });
    }

    #[Test]
    public function it_survives_connect_failure_during_init(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $connector->failOnConnect = true;

            $pool = new Pool($connector, new PoolConfig(minConnections: 3, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            $stats = $pool->stats();
            self::assertSame(0, $stats->total); // all failed
            self::assertSame(0, $stats->idle);

            // On-demand creation should work if we fix the connector
            $connector->failOnConnect = false;
            $conn = $pool->borrow();
            self::assertInstanceOf(FakeConnection::class, $conn);
            self::assertSame(1, $pool->stats()->total);

            $pool->release($conn);
            $pool->close();
        });
    }

    #[Test]
    public function it_reports_is_closed(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 1, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            self::assertFalse($pool->isClosed());

            $pool->close();

            self::assertTrue($pool->isClosed());
        });
    }

    #[Test]
    public function it_close_is_idempotent(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 2, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            $pool->close();
            $pool->close(); // second close — no error

            self::assertTrue($pool->isClosed());
        });
    }

    #[Test]
    public function it_tracks_all_stats(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(minConnections: 2, maxConnections: 5, maxIdleTime: 0.0));
            $pool->init();

            $c1 = $pool->borrow();
            $c2 = $pool->borrow(); // on-demand
            $c3 = $pool->borrow(); // on-demand

            $pool->release($c1);
            $pool->release($c2);
            $pool->discard($c3);

            $stats = $pool->stats();
            self::assertSame(3, $stats->borrowCount);
            self::assertSame(2, $stats->releaseCount);
            self::assertSame(1, $stats->discardCount);
            self::assertSame(3, $stats->createCount); // 2 init + 1 on-demand (borrows 1-2 from channel)
            self::assertSame(2, $stats->idle);

            $pool->close();
        });
    }
}

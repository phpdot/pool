<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Integration;

use PHPdot\Pool\Exception\BorrowTimeoutException;
use PHPdot\Pool\Pool;
use PHPdot\Pool\PoolConfig;
use PHPdot\Pool\Tests\Fixtures\FakeConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class PoolExhaustionTest extends TestCase
{
    #[Test]
    public function it_throws_on_borrow_timeout(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 1,
                maxConnections: 1,
                borrowTimeout: 0.1,
                maxIdleTime: 0.0,
            ));
            $pool->init();

            $conn = $pool->borrow(); // takes the only connection

            $timedOut = false;

            Coroutine::create(function () use ($pool, &$timedOut): void {
                try {
                    $pool->borrow(); // should timeout
                } catch (BorrowTimeoutException) {
                    $timedOut = true;
                }
            });

            Coroutine::sleep(0.3); // let timeout happen

            self::assertTrue($timedOut);
            self::assertSame(1, $pool->stats()->timeoutCount);

            $pool->release($conn);
            $pool->close();
        });
    }

    #[Test]
    public function it_resumes_waiting_coroutine_on_release(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 1,
                maxConnections: 1,
                borrowTimeout: 2.0,
                maxIdleTime: 0.0,
            ));
            $pool->init();

            $conn = $pool->borrow();
            $gotConnection = false;

            // Coroutine waits for a connection
            Coroutine::create(function () use ($pool, &$gotConnection): void {
                $c = $pool->borrow(); // will wait
                $gotConnection = true;
                $pool->release($c);
            });

            Coroutine::sleep(0.05); // let waiter start waiting

            self::assertFalse($gotConnection); // still waiting

            $pool->release($conn); // release — waiter should get it

            Coroutine::sleep(0.05); // let waiter run

            self::assertTrue($gotConnection);
            self::assertSame(0, $pool->stats()->timeoutCount);

            $pool->close();
        });
    }
}

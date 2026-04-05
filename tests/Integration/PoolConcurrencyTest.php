<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Integration;

use PHPdot\Pool\Pool;
use PHPdot\Pool\PoolConfig;
use PHPdot\Pool\Tests\Fixtures\FakeConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

final class PoolConcurrencyTest extends TestCase
{
    #[Test]
    public function it_handles_concurrent_borrow_and_release(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 2,
                maxConnections: 5,
                borrowTimeout: 5.0,
                maxIdleTime: 0.0,
            ));
            $pool->init();

            $completed = 0;
            $errors = [];

            // 20 coroutines, 5 max connections — forces waiting
            for ($i = 0; $i < 20; $i++) {
                Coroutine::create(function () use ($pool, &$completed, &$errors): void {
                    try {
                        $conn = $pool->borrow();
                        Coroutine::sleep(0.01); // simulate work
                        $pool->release($conn);
                        $completed++;
                    } catch (\Throwable $e) {
                        $errors[] = $e->getMessage();
                    }
                });
            }

            // Wait for all coroutines
            Coroutine::sleep(0.5);

            self::assertSame(20, $completed);
            self::assertSame([], $errors);
            self::assertSame(0, $pool->stats()->active);
            self::assertLessThanOrEqual(5, $pool->stats()->total);

            $pool->close();
        });
    }

    #[Test]
    public function it_never_exceeds_max_connections(): void
    {
        \Co\run(function (): void {
            $connector = new FakeConnector();
            $pool = new Pool($connector, new PoolConfig(
                minConnections: 1,
                maxConnections: 3,
                borrowTimeout: 5.0,
                maxIdleTime: 0.0,
            ));
            $pool->init();

            $maxObserved = 0;
            $wg = new Coroutine\WaitGroup();

            for ($i = 0; $i < 10; $i++) {
                $wg->add();
                Coroutine::create(function () use ($pool, &$maxObserved, $wg): void {
                    $conn = $pool->borrow();
                    $current = $pool->stats()->total;
                    if ($current > $maxObserved) {
                        $maxObserved = $current;
                    }
                    Coroutine::sleep(0.01);
                    $pool->release($conn);
                    $wg->done();
                });
            }

            $wg->wait(5.0);

            self::assertLessThanOrEqual(3, $maxObserved);

            $pool->close();
        });
    }
}

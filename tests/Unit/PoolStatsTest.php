<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Unit;

use PHPdot\Pool\PoolStats;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PoolStatsTest extends TestCase
{
    #[Test]
    public function it_stores_all_fields(): void
    {
        $stats = new PoolStats(
            active: 3,
            idle: 7,
            total: 10,
            borrowCount: 100,
            releaseCount: 97,
            discardCount: 2,
            createCount: 12,
            closeCount: 4,
            timeoutCount: 1,
            waitingCount: 5,
        );

        self::assertSame(3, $stats->active);
        self::assertSame(7, $stats->idle);
        self::assertSame(10, $stats->total);
        self::assertSame(100, $stats->borrowCount);
        self::assertSame(97, $stats->releaseCount);
        self::assertSame(2, $stats->discardCount);
        self::assertSame(12, $stats->createCount);
        self::assertSame(4, $stats->closeCount);
        self::assertSame(1, $stats->timeoutCount);
        self::assertSame(5, $stats->waitingCount);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $reflection = new \ReflectionClass(PoolStats::class);

        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->isFinal());
    }
}

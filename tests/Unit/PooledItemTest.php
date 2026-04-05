<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Unit;

use PHPdot\Pool\PooledItem;
use PHPdot\Pool\Tests\Fixtures\FakeConnection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PooledItemTest extends TestCase
{
    #[Test]
    public function it_stores_connection_and_timestamp(): void
    {
        $conn = new FakeConnection();
        $now = microtime(true);
        $item = new PooledItem($conn, $now);

        self::assertSame($conn, $item->connection);
        self::assertSame($now, $item->lastReleasedAt);
    }

    #[Test]
    public function it_allows_timestamp_update(): void
    {
        $item = new PooledItem(new FakeConnection(), 1000.0);
        $item->lastReleasedAt = 2000.0;

        self::assertSame(2000.0, $item->lastReleasedAt);
    }

    #[Test]
    public function it_has_readonly_connection(): void
    {
        $reflection = new \ReflectionProperty(PooledItem::class, 'connection');

        self::assertTrue($reflection->isReadOnly());
    }
}

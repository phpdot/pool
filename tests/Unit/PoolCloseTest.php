<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Unit;

use PHPdot\Pool\Pool;
use PHPdot\Pool\Tests\Fixtures\FakeConnector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PoolCloseTest extends TestCase
{
    #[Test]
    public function it_closes_outside_a_coroutine_without_error(): void
    {
        // close() drains the channel via Channel::pop, which requires a coroutine.
        // When called from onWorkerStop during worker shutdown there is none, so
        // it must skip the drain rather than throw Swoole\Error.
        $pool = new Pool(new FakeConnector());

        $pool->close();

        self::assertTrue($pool->isClosed());
    }

    #[Test]
    public function it_is_idempotent_when_closed_twice(): void
    {
        $pool = new Pool(new FakeConnector());

        $pool->close();
        $pool->close();

        self::assertTrue($pool->isClosed());
    }
}

<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Unit;

use PHPdot\Pool\PoolConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PoolConfigTest extends TestCase
{
    #[Test]
    public function it_has_sensible_defaults(): void
    {
        $config = new PoolConfig();

        self::assertSame(2, $config->minConnections);
        self::assertSame(10, $config->maxConnections);
        self::assertSame(3.0, $config->borrowTimeout);
        self::assertSame(300.0, $config->maxIdleTime);
        self::assertSame(30.0, $config->idleCheckInterval);
        self::assertSame(0.0, $config->heartbeatInterval);
    }

    #[Test]
    public function it_accepts_custom_values(): void
    {
        $config = new PoolConfig(
            minConnections: 5,
            maxConnections: 20,
            borrowTimeout: 5.0,
            maxIdleTime: 60.0,
            idleCheckInterval: 10.0,
            heartbeatInterval: 15.0,
        );

        self::assertSame(5, $config->minConnections);
        self::assertSame(20, $config->maxConnections);
        self::assertSame(5.0, $config->borrowTimeout);
        self::assertSame(60.0, $config->maxIdleTime);
        self::assertSame(10.0, $config->idleCheckInterval);
        self::assertSame(15.0, $config->heartbeatInterval);
    }

    #[Test]
    public function it_is_readonly(): void
    {
        $reflection = new \ReflectionClass(PoolConfig::class);

        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->isFinal());
    }

    #[Test]
    public function it_allows_zero_idle_time_to_disable_cleanup(): void
    {
        $config = new PoolConfig(maxIdleTime: 0.0);

        self::assertSame(0.0, $config->maxIdleTime);
    }

    #[Test]
    public function it_allows_zero_heartbeat_to_disable(): void
    {
        $config = new PoolConfig(heartbeatInterval: 0.0);

        self::assertSame(0.0, $config->heartbeatInterval);
    }
}

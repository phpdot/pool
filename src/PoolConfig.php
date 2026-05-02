<?php

declare(strict_types=1);

namespace PHPdot\Pool;

use PHPdot\Container\Attribute\Config;

/**
 * Pool configuration. Immutable.
 */
#[Config('pool')]
final readonly class PoolConfig
{
    /**
     * @param int $minConnections Pre-created on init. Pool never shrinks below this. Default: 2.
     * @param int $maxConnections Hard limit per worker. Default: 10.
     * @param float $borrowTimeout Seconds to wait when pool exhausted. Default: 3.0.
     * @param float $maxIdleTime Seconds before idle cleanup. 0.0 = disabled. Default: 300.0.
     * @param float $idleCheckInterval Seconds between idle cleanup runs. Default: 30.0.
     * @param float $heartbeatInterval Seconds between heartbeat checks. 0.0 = disabled. Default: 0.0.
     * @param float $validateOnBorrowAfterIdle TTL gate for `isAlive()` check on borrow.
     *     Positive: validate only when the popped connection has been idle ≥ this many seconds.
     *     0.0: validate every borrow.
     *     Negative: disabled (no validation on borrow).
     *     Default: 5.0.
     * @param bool $validateOnReturn When true, call `isAlive()` on release; close (don't re-pool)
     *     dead connections. Off by default — the connection just succeeded a query, so the check
     *     is usually redundant. Enable for belt-and-suspenders setups.
     */
    public function __construct(
        public int $minConnections = 2,
        public int $maxConnections = 10,
        public float $borrowTimeout = 3.0,
        public float $maxIdleTime = 300.0,
        public float $idleCheckInterval = 30.0,
        public float $heartbeatInterval = 0.0,
        public float $validateOnBorrowAfterIdle = 5.0,
        public bool $validateOnReturn = false,
    ) {
    }
}

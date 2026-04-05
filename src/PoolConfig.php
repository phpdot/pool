<?php

declare(strict_types=1);

namespace PHPdot\Pool;

/**
 * Pool configuration. Immutable.
 */
final readonly class PoolConfig
{
    /**
     * @param int $minConnections Pre-created on init. Pool never shrinks below this. Default: 2.
     * @param int $maxConnections Hard limit per worker. Default: 10.
     * @param float $borrowTimeout Seconds to wait when pool exhausted. Default: 3.0.
     * @param float $maxIdleTime Seconds before idle cleanup. 0.0 = disabled. Default: 300.0.
     * @param float $idleCheckInterval Seconds between idle cleanup runs. Default: 30.0.
     * @param float $heartbeatInterval Seconds between heartbeat checks. 0.0 = disabled. Default: 0.0.
     */
    public function __construct(
        public int $minConnections = 2,
        public int $maxConnections = 10,
        public float $borrowTimeout = 3.0,
        public float $maxIdleTime = 300.0,
        public float $idleCheckInterval = 30.0,
        public float $heartbeatInterval = 0.0,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace PHPdot\Pool;

/**
 * Pool statistics snapshot. Used for monitoring and health checks.
 */
final readonly class PoolStats
{
    public function __construct(
        public int $active,
        public int $idle,
        public int $total,
        public int $borrowCount,
        public int $releaseCount,
        public int $discardCount,
        public int $createCount,
        public int $closeCount,
        public int $timeoutCount,
        public int $waitingCount,
    ) {
    }
}

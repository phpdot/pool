<?php

declare(strict_types=1);

namespace PHPdot\Pool;

/**
 * Internal wrapper pairing a connection with its last-released timestamp.
 *
 * @internal
 */
final class PooledItem
{
    public function __construct(
        public readonly object $connection,
        public float $lastReleasedAt,
    ) {
    }
}

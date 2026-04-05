<?php

declare(strict_types=1);

namespace PHPdot\Pool\Exception;

/**
 * All maxConnections are borrowed and no one released within borrowTimeout.
 */
final class BorrowTimeoutException extends PoolException
{
}

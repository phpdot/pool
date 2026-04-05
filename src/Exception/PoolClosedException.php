<?php

declare(strict_types=1);

namespace PHPdot\Pool\Exception;

/**
 * Pool was shut down via close(). No more connections can be borrowed.
 */
final class PoolClosedException extends PoolException
{
}

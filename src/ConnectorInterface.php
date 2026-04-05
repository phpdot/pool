<?php

declare(strict_types=1);

namespace PHPdot\Pool;

/**
 * Defines how to create, check, and destroy pooled objects.
 *
 * Implemented by the framework kernel for each driver.
 * The pool calls connect() when it needs a new object,
 * isAlive() during heartbeat checks (when enabled),
 * and close() when it needs to destroy one.
 */
interface ConnectorInterface
{
    /**
     * Create a new connection object, fully initialized and ready to use.
     *
     * @throws \Throwable If the connection cannot be established
     */
    public function connect(): object;

    /**
     * Check if a connection is still usable.
     *
     * MUST be lightweight — local state check, no network round trip.
     * Only called when heartbeat is enabled (heartbeatInterval > 0.0).
     */
    public function isAlive(object $connection): bool;

    /**
     * Close and destroy a connection object permanently.
     *
     * Must not throw. If the connection is already dead, ignore silently.
     */
    public function close(object $connection): void;
}

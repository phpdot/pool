<?php

declare(strict_types=1);

namespace PHPdot\Pool;

use PHPdot\Contracts\Pool\ConnectorInterface;
use PHPdot\Pool\Exception\BorrowTimeoutException;
use PHPdot\Pool\Exception\PoolClosedException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;

/**
 * Generic coroutine-safe connection pool built on Swoole\Coroutine\Channel.
 *
 * Holds objects of any type. Manages creation, borrowing, releasing,
 * idle cleanup, optional heartbeat, and shutdown.
 *
 * Created in onWorkerStart, closed in onWorkerStop.
 * Coroutine safety provided by Channel at the C level.
 */
final class Pool
{
    /** @var Channel<PooledItem> */
    private Channel $channel;

    private int $currentCount = 0;

    /** @var array<int, true> spl_object_id => true for borrowed connections */
    private array $borrowed = [];

    private bool $closed = false;

    private ?int $idleTimerId = null;

    private ?int $heartbeatTimerId = null;

    private int $borrowCount = 0;

    private int $releaseCount = 0;

    private int $discardCount = 0;

    private int $createCount = 0;

    private int $closeCount = 0;

    private int $timeoutCount = 0;

    public function __construct(
        private readonly ConnectorInterface $connector,
        private readonly PoolConfig $config = new PoolConfig(),
    ) {
        $this->channel = new Channel($config->maxConnections);
    }

    /**
     * Initialize the pool: create minConnections and start timers.
     *
     * MUST be called inside a Swoole coroutine context.
     */
    public function init(): void
    {
        $now = microtime(true);

        for ($i = 0; $i < $this->config->minConnections; $i++) {
            try {
                $connection = $this->connector->connect();
                $this->channel->push(new PooledItem($connection, $now));
                $this->currentCount++;
                $this->createCount++;
            } catch (\Throwable) {
                // Failed to create — pool starts with fewer connections.
                // On-demand creation in borrow() fills the gap.
            }
        }

        $this->startTimers();
    }

    /**
     * Borrow a connection from the pool.
     *
     * @throws BorrowTimeoutException If no connection available within borrowTimeout
     * @throws PoolClosedException If the pool has been closed
     */
    public function borrow(): object
    {
        if ($this->closed) {
            throw new PoolClosedException('Connection pool is closed');
        }

        $deadline = microtime(true) + $this->config->borrowTimeout;

        while (true) {
            // Fast path: non-blocking pop from channel
            $item = $this->channel->pop(0.001);

            if ($item instanceof PooledItem) {
                $live = $this->validateBorrowedItem($item);

                if ($live !== null) {
                    return $this->markBorrowed($live);
                }

                continue; // dead connection — already closed; try next iteration
            }

            // Channel empty — can we create a new connection?
            if ($this->currentCount < $this->config->maxConnections) {
                // Reserve the slot BEFORE I/O to prevent race condition.
                // connect() yields — without this, multiple coroutines could
                // pass the < maxConnections check simultaneously.
                $this->currentCount++;

                try {
                    $connection = $this->connector->connect();
                    $this->createCount++;

                    return $this->markBorrowed($connection);
                } catch (\Throwable $e) {
                    $this->currentCount--; // Release reserved slot on failure

                    throw $e;
                }
            }

            // At capacity — wait for a release
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                break;
            }

            $item = $this->channel->pop($remaining);

            if ($item instanceof PooledItem) {
                $live = $this->validateBorrowedItem($item);

                if ($live !== null) {
                    return $this->markBorrowed($live);
                }

                continue;
            }

            break; // pop timed out or channel closed
        }

        // Timeout or channel closed
        $this->timeoutCount++;

        throw new BorrowTimeoutException(
            sprintf(
                'No connection available within %.1fs (pool: %d/%d, waiting: %d)',
                $this->config->borrowTimeout,
                $this->currentCount,
                $this->config->maxConnections,
                $this->getWaitingCount(),
            ),
        );
    }

    /**
     * Validate a popped item per the validate-on-borrow policy.
     *
     * Returns the connection if it should be handed out, or null if it was
     * dead and has been closed (caller should try again).
     */
    private function validateBorrowedItem(PooledItem $item): object|null
    {
        if (!$this->shouldValidateOnBorrow($item)) {
            return $item->connection;
        }

        if ($this->connector->isAlive($item->connection)) {
            return $item->connection;
        }

        $this->closeConnection($item->connection);

        return null;
    }

    /**
     * Decide whether the popped item needs an `isAlive()` check before being
     * handed to the caller. Honours `validateOnBorrowAfterIdle`:
     * positive = TTL gate (validate when idle ≥ value), 0.0 = always validate,
     * negative = disabled.
     */
    private function shouldValidateOnBorrow(PooledItem $item): bool
    {
        $threshold = $this->config->validateOnBorrowAfterIdle;

        if ($threshold < 0.0) {
            return false;
        }

        return microtime(true) - $item->lastReleasedAt >= $threshold;
    }

    /**
     * Return a connection to the pool for reuse.
     *
     * Double release is silently ignored to prevent Channel corruption.
     */
    public function release(object $connection): void
    {
        $id = spl_object_id($connection);

        if (!isset($this->borrowed[$id])) {
            return; // Already released or discarded — ignore
        }

        unset($this->borrowed[$id]);
        $this->releaseCount++;

        if ($this->closed) {
            $this->closeConnection($connection);

            return;
        }

        if ($this->config->validateOnReturn && !$this->connector->isAlive($connection)) {
            $this->closeConnection($connection);
            $this->refill();

            return;
        }

        $this->channel->push(new PooledItem($connection, microtime(true)));
    }

    /**
     * Discard a connection permanently. Not returned to pool.
     */
    public function discard(object $connection): void
    {
        $id = spl_object_id($connection);

        if (!isset($this->borrowed[$id])) {
            return; // Already released or discarded
        }

        unset($this->borrowed[$id]);
        $this->discardCount++;
        $this->closeConnection($connection);
    }

    /**
     * Get current pool statistics snapshot.
     */
    public function stats(): PoolStats
    {
        return new PoolStats(
            active: count($this->borrowed),
            idle: $this->channel->length(),
            total: $this->currentCount,
            borrowCount: $this->borrowCount,
            releaseCount: $this->releaseCount,
            discardCount: $this->discardCount,
            createCount: $this->createCount,
            closeCount: $this->closeCount,
            timeoutCount: $this->timeoutCount,
            waitingCount: $this->getWaitingCount(),
        );
    }

    /**
     * Shut down the pool. Stops timers and closes all idle connections.
     * Borrowed connections will be closed when released/discarded.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->stopTimers();

        // Draining the channel needs a coroutine (Channel::pop). When close()
        // runs outside one — e.g. onWorkerStop during worker shutdown — skip the
        // drain: closing the channel releases the pooled items and their
        // connections close on destruction.
        if (Coroutine::getCid() !== -1) {
            while (!$this->channel->isEmpty()) {
                $item = $this->channel->pop(0.001);

                if ($item instanceof PooledItem) {
                    $this->closeConnection($item->connection);
                }
            }
        }

        $this->channel->close();
    }

    /**
     * Check if the pool has been closed.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Get the number of coroutines waiting for a connection.
     */
    private function getWaitingCount(): int
    {
        $stats = $this->channel->stats();
        $consumerNum = $stats['consumer_num'] ?? 0;

        return is_int($consumerNum) ? $consumerNum : 0;
    }

    /**
     * Mark a connection as borrowed and increment the borrow counter.
     */
    private function markBorrowed(object $connection): object
    {
        $this->borrowed[spl_object_id($connection)] = true;
        $this->borrowCount++;

        return $connection;
    }

    /**
     * Close a connection and update counters.
     */
    private function closeConnection(object $connection): void
    {
        try {
            $this->connector->close($connection);
        } catch (\Throwable) {
            // close() must not crash the pool
        }

        $this->currentCount--;
        $this->closeCount++;
    }

    /**
     * Start idle cleanup and heartbeat timers.
     */
    private function startTimers(): void
    {
        // Idle cleanup timer
        if ($this->config->maxIdleTime > 0.0 && $this->config->idleCheckInterval > 0.0) {
            $timerId = Timer::tick(
                (int) ($this->config->idleCheckInterval * 1000),
                $this->idleCleanup(...),
            );

            if ($timerId !== false) {
                $this->idleTimerId = $timerId;
            }
        }

        // Heartbeat timer (separate from idle, disabled by default)
        if ($this->config->heartbeatInterval > 0.0) {
            $timerId = Timer::tick(
                (int) ($this->config->heartbeatInterval * 1000),
                $this->heartbeat(...),
            );

            if ($timerId !== false) {
                $this->heartbeatTimerId = $timerId;
            }
        }
    }

    /**
     * Stop all timers.
     */
    private function stopTimers(): void
    {
        if ($this->idleTimerId !== null) {
            Timer::clear($this->idleTimerId);
            $this->idleTimerId = null;
        }

        if ($this->heartbeatTimerId !== null) {
            Timer::clear($this->heartbeatTimerId);
            $this->heartbeatTimerId = null;
        }
    }

    /**
     * Idle cleanup: close connections that have been idle too long.
     * Maintains minConnections. Skips if borrowers are waiting.
     */
    private function idleCleanup(): void
    {
        if ($this->closed) {
            return;
        }

        // Don't drain the channel if coroutines are waiting for connections
        if ($this->getWaitingCount() > 0) {
            return;
        }

        $now = microtime(true);
        $keep = [];

        while (!$this->channel->isEmpty()) {
            $item = $this->channel->pop(0.001);

            if (!$item instanceof PooledItem) {
                break;
            }

            $idleSeconds = $now - $item->lastReleasedAt;

            if ($idleSeconds > $this->config->maxIdleTime && $this->currentCount > $this->config->minConnections) {
                $this->closeConnection($item->connection);
            } else {
                $keep[] = $item;
            }
        }

        // Push back kept connections
        foreach ($keep as $item) {
            $this->channel->push($item);
        }

        // Refill to minConnections if needed
        $this->refill();
    }

    /**
     * Heartbeat: check idle connections are alive, replace dead ones.
     * Skips if borrowers are waiting.
     */
    private function heartbeat(): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->getWaitingCount() > 0) {
            return;
        }

        $keep = [];

        while (!$this->channel->isEmpty()) {
            $item = $this->channel->pop(0.001);

            if (!$item instanceof PooledItem) {
                break;
            }

            if ($this->connector->isAlive($item->connection)) {
                $keep[] = $item;
            } else {
                $this->closeConnection($item->connection);
            }
        }

        foreach ($keep as $item) {
            $this->channel->push($item);
        }

        $this->refill();
    }

    /**
     * Refill pool to minConnections if it has dropped below.
     */
    private function refill(): void
    {
        $now = microtime(true);

        while ($this->currentCount < $this->config->minConnections) {
            try {
                $connection = $this->connector->connect();
                $this->channel->push(new PooledItem($connection, $now));
                $this->currentCount++;
                $this->createCount++;
            } catch (\Throwable) {
                break; // Can't create more — stop trying
            }
        }
    }
}

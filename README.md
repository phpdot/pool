# phpdot/pool

Generic coroutine-safe connection pool for Swoole. Holds any object. Channel-based with idle cleanup, optional heartbeat, and leak prevention. Zero phpdot dependencies.

---

## Table of Contents

- [Install](#install)
- [Architecture](#architecture)
  - [How It Works](#how-it-works)
  - [Package Structure](#package-structure)
- [ConnectorInterface](#connectorinterface)
- [Pool](#pool)
  - [Lifecycle](#lifecycle)
  - [Borrow](#borrow)
  - [Release](#release)
  - [Discard](#discard)
  - [Stats](#stats)
  - [Close](#close)
- [PoolConfig](#poolconfig)
- [Idle Cleanup](#idle-cleanup)
- [Heartbeat](#heartbeat)
- [Edge Cases](#edge-cases)
  - [Double Release](#double-release)
  - [Pool Exhaustion](#pool-exhaustion)
  - [Connect Failure During Init](#connect-failure-during-init)
  - [Borrow After Close](#borrow-after-close)
  - [Race Condition Prevention](#race-condition-prevention)
- [Framework Wiring](#framework-wiring)
- [API Reference](#api-reference)
  - [ConnectorInterface API](#connectorinterface-api)
  - [Pool API](#pool-api)
  - [PoolConfig API](#poolconfig-api)
  - [PoolStats API](#poolstats-api)
  - [Exceptions API](#exceptions-api)
- [License](#license)

---

## Install

```bash
composer require phpdot/pool
```

| Requirement | Version |
|-------------|---------|
| PHP | >= 8.3 |
| ext-swoole | >= 6.0 |

---

## Architecture

### How It Works

```
Swoole Worker Process (single OS process, 100+ coroutines)
    │
    Pool (Channel-based, per worker)
    │
    ├── init()     → pre-create minConnections, start timers
    │
    ├── borrow()   → pop from Channel (or create on-demand)
    │   ├── Channel has idle item → return immediately
    │   ├── Channel empty, below max → create new (slot reserved before I/O)
    │   └── Channel empty, at max → suspend coroutine, wait for release
    │
    ├── release()  → push back to Channel (with timestamp)
    │   └── Double release silently ignored (spl_object_id tracking)
    │
    ├── discard()  → close permanently, don't return to Channel
    │
    └── close()    → stop timers, close all idle connections
```

The pool is built on `Swoole\Coroutine\Channel` — a coroutine-safe bounded FIFO queue. `pop()` suspends only the calling coroutine (not the worker process). `push()` wakes the next waiting coroutine. Lock-free at the C level.

### Package Structure

```
src/
├── ConnectorInterface.php      # How to create, check, close connections
├── Pool.php                    # Channel + Timers + borrow/release/discard
├── PoolConfig.php              # 6 readonly config properties
├── PoolStats.php               # 10 readonly monitoring counters
├── PooledItem.php              # Internal: connection + lastReleasedAt
└── Exception/
    ├── PoolException.php       # Base
    ├── BorrowTimeoutException.php
    └── PoolClosedException.php
```

8 files. One dependency (ext-swoole).

---

## ConnectorInterface

The pool doesn't know what it's pooling. The connector tells it how to create, check, and close objects:

```php
interface ConnectorInterface
{
    public function connect(): object;
    public function isAlive(object $connection): bool;
    public function close(object $connection): void;
}
```

Implementations live in the framework kernel, not in this package:

```php
// Example: MongoDB connector (lives in phpdot/dot)
final class MongoConnector implements ConnectorInterface
{
    public function connect(): object
    {
        $connection = new Connection($this->config);
        $connection->connect();
        return $connection;
    }

    public function isAlive(object $connection): bool
    {
        return $connection->isConnected(); // local check, no network
    }

    public function close(object $connection): void
    {
        $connection->close();
    }
}
```

---

## Pool

### Lifecycle

```php
use PHPdot\Pool\Pool;
use PHPdot\Pool\PoolConfig;

// Created in onWorkerStart (after fork)
$pool = new Pool($connector, new PoolConfig(
    minConnections: 2,
    maxConnections: 10,
));

$pool->init(); // pre-creates minConnections, starts timers

// ... application runs, coroutines borrow and release ...

$pool->close(); // onWorkerStop — closes everything
```

### Borrow

```php
$connection = $pool->borrow();
```

1. If the Channel has idle connections → returns one immediately
2. If empty but below `maxConnections` → creates a new one (slot reserved before I/O to prevent race)
3. If at capacity → suspends the coroutine until a connection is released
4. If timeout → throws `BorrowTimeoutException`
5. If pool closed → throws `PoolClosedException`

### Release

```php
$pool->release($connection);
```

Returns the connection to the Channel. Double release is silently ignored (tracked via `spl_object_id`). If the pool was closed while the connection was borrowed, the connection is closed instead.

### Discard

```php
$pool->discard($connection);
```

Permanently closes the connection. Not returned to the Channel. Decrements the pool count, allowing a new connection to be created on the next borrow.

### Stats

```php
$stats = $pool->stats();

$stats->active;       // currently borrowed
$stats->idle;         // sitting in Channel
$stats->total;        // active + idle
$stats->borrowCount;  // total borrows since init
$stats->releaseCount;
$stats->discardCount;
$stats->createCount;
$stats->closeCount;
$stats->timeoutCount;
$stats->waitingCount; // coroutines waiting for a connection right now
```

### Close

```php
$pool->close();
```

Stops timers, closes all idle connections. Borrowed connections are closed when released/discarded. Idempotent.

---

## PoolConfig

```php
new PoolConfig(
    minConnections: 2,        // pre-created, never shrink below
    maxConnections: 10,       // hard limit per worker
    borrowTimeout: 3.0,       // seconds to wait when exhausted
    maxIdleTime: 300.0,       // seconds before idle cleanup (0.0 = disabled)
    idleCheckInterval: 30.0,  // seconds between cleanup runs
    heartbeatInterval: 0.0,   // seconds between heartbeat (0.0 = disabled)
);
```

**Total database connections = workers x maxConnections.** 4 workers x 10 max = 40 max connections.

---

## Idle Cleanup

Purpose: shrink the pool after traffic spikes. Not for connection health.

When `maxIdleTime > 0.0`, a timer runs every `idleCheckInterval` seconds:
1. Skip if coroutines are waiting for connections
2. Pop idle items from Channel
3. Close items idle longer than `maxIdleTime` (only if above `minConnections`)
4. Push back the rest
5. Refill to `minConnections` if needed

```php
new PoolConfig(
    minConnections: 2,
    maxConnections: 10,
    maxIdleTime: 300.0,       // close after 5 min idle
    idleCheckInterval: 30.0,  // check every 30s
);
```

Set `maxIdleTime: 0.0` to disable.

---

## Heartbeat

Purpose: safety net for dead connections. Disabled by default.

When `heartbeatInterval > 0.0`, a timer checks idle connections via `ConnectorInterface::isAlive()`:
1. Skip if coroutines are waiting
2. Pop all idle items
3. Close dead ones (`isAlive() === false`)
4. Push back alive ones
5. Refill to `minConnections`

```php
// Enable in production during incidents (config change, no code deploy)
new PoolConfig(heartbeatInterval: 10.0);
```

`isAlive()` must be lightweight — local state check, no network round trip.

---

## Edge Cases

### Double Release

Tracked via `spl_object_id`. Second `release()` on the same connection is silently ignored. Prevents Channel corruption.

### Pool Exhaustion

All connections borrowed, new coroutine calls `borrow()`:
- Coroutine is **suspended** (not the worker process)
- Other coroutines continue running
- When any coroutine releases → waiting coroutine resumes
- If `borrowTimeout` expires → `BorrowTimeoutException`

### Connect Failure During Init

If `connector->connect()` throws during `init()`, the error is skipped. The pool starts with fewer connections. On-demand creation in `borrow()` fills the gap.

### Borrow After Close

Throws `PoolClosedException` immediately.

### Race Condition Prevention

On-demand creation in `borrow()` increments `currentCount` **before** calling `connect()` (which yields on I/O). This prevents multiple coroutines from passing the `< maxConnections` check simultaneously — the slot is reserved before any yield point.

---

## Framework Wiring

The developer never touches the pool. The framework kernel wires it:

```php
// Swoole mode (in onWorkerStart)
$pool = new Pool(new MongoConnector($config), new PoolConfig(...));
$pool->init();

$container->scoped(Connection::class, function () use ($pool) {
    $connection = $pool->borrow();
    Coroutine::defer(fn () => $pool->release($connection));
    return $connection;
});

// FPM mode — no pool, direct binding
$container->singleton(Connection::class, fn () => new Connection($config));
```

Developer code is identical in both modes:

```php
public function __construct(private Connection $mongodb) {}
```

---

## API Reference

### ConnectorInterface API

```
interface ConnectorInterface

connect(): object               // create new connection
isAlive(object $connection): bool  // local health check
close(object $connection): void    // destroy permanently
```

### Pool API

```
final class Pool

__construct(ConnectorInterface $connector, PoolConfig $config = new PoolConfig())
init(): void                                    // pre-create + start timers
borrow(): object                                // get a connection
release(object $connection): void               // return to pool
discard(object $connection): void               // close permanently
stats(): PoolStats                              // monitoring snapshot
close(): void                                   // shutdown pool
isClosed(): bool
```

### PoolConfig API

```
final readonly class PoolConfig

__construct(
    public int   $minConnections    = 2,
    public int   $maxConnections    = 10,
    public float $borrowTimeout     = 3.0,
    public float $maxIdleTime       = 300.0,
    public float $idleCheckInterval = 30.0,
    public float $heartbeatInterval = 0.0,
)
```

### PoolStats API

```
final readonly class PoolStats

public int $active        // currently borrowed
public int $idle          // in Channel
public int $total         // active + idle
public int $borrowCount   // lifetime borrows
public int $releaseCount  // lifetime releases
public int $discardCount  // lifetime discards
public int $createCount   // lifetime creates
public int $closeCount    // lifetime closes
public int $timeoutCount  // lifetime timeouts
public int $waitingCount  // coroutines waiting now
```

### Exceptions API

```
PoolException (extends RuntimeException)
├── BorrowTimeoutException   // pool exhausted
└── PoolClosedException      // pool shut down
```

---

## License

MIT

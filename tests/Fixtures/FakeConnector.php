<?php

declare(strict_types=1);

namespace PHPdot\Pool\Tests\Fixtures;

use PHPdot\Contracts\Pool\ConnectorInterface;

final class FakeConnector implements ConnectorInterface
{
    public int $createCount = 0;

    public int $closeCount = 0;

    public int $isAliveCount = 0;

    public bool $aliveResult = true;

    public bool $failOnConnect = false;

    public function connect(): object
    {
        if ($this->failOnConnect) {
            throw new \RuntimeException('Connection failed');
        }

        $this->createCount++;

        return new FakeConnection();
    }

    public function isAlive(object $connection): bool
    {
        $this->isAliveCount++;

        return $this->aliveResult;
    }

    public function close(object $connection): void
    {
        $this->closeCount++;

        if ($connection instanceof FakeConnection) {
            $connection->closed = true;
        }
    }
}

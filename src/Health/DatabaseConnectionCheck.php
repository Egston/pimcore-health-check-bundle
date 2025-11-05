<?php

namespace Egston\PimcoreHealthCheckBundle\Health;

use Doctrine\DBAL\Connection;

class DatabaseConnectionCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $validationQuery = 'SELECT 1'
    ) {
    }

    public function getName(): string
    {
        return 'database';
    }

    public function assert(): void
    {
        try {
            $this->connection->fetchOne($this->validationQuery);
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                sprintf('Database connectivity check failed: %s', $exception->getMessage()),
                (int) $exception->getCode(),
                $exception
            );
        }
    }
}

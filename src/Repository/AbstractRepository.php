<?php

declare(strict_types=1);

namespace App\Repository;

use App\Infrastructure\Database;
use PDO;

abstract class AbstractRepository
{
    protected readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    protected function execute(string $sql, array $params = []): void
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    protected function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     * @param array<int|string, mixed> $params
     * @param callable(array<string, mixed>): T $hydrate
     * @return T|null
     */
    protected function fetchOne(string $sql, array $params, callable $hydrate): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $hydrate($row);
    }

    /**
     * @template T
     * @param array<int|string, mixed> $params
     * @param callable(array<string, mixed>): T $hydrate
     * @return list<T>
     */
    protected function fetchAll(string $sql, array $params, callable $hydrate): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map($hydrate, $stmt->fetchAll());
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Vet;
use App\Infrastructure\Database;
use PDO;

final class VetRepository
{
    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function createProfile(int $userId, string $firstName, string $lastName, string $specialty): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO vets (user_id, first_name, last_name, specialty)
             VALUES (:user_id, :first_name, :last_name, :specialty)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'specialty' => $specialty,
        ]);
    }

    public function findByUserId(int $userId): ?Vet
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, first_name, last_name, specialty FROM vets WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findById(int $id): ?Vet
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, first_name, last_name, specialty FROM vets WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return list<Vet>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, user_id, first_name, last_name, specialty FROM vets ORDER BY last_name, first_name'
        );

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Vet
    {
        return new Vet(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            (string) $row['specialty'],
        );
    }
}

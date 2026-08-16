<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Owner;
use App\Infrastructure\Database;
use PDO;

final class OwnerRepository
{
    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function createProfile(int $userId, string $firstName, string $lastName, string $phone, string $address): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO owners (user_id, first_name, last_name, phone, address)
             VALUES (:user_id, :first_name, :last_name, :phone, :address)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'address' => $address,
        ]);
    }

    public function findByUserId(int $userId): ?Owner
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, first_name, last_name, phone, address FROM owners WHERE user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Owner
    {
        return new Owner(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['first_name'],
            (string) $row['last_name'],
            (string) $row['phone'],
            (string) $row['address'],
        );
    }
}

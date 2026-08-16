<?php

declare(strict_types=1);

namespace App\Repository;

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
}

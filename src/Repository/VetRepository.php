<?php

declare(strict_types=1);

namespace App\Repository;

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
}

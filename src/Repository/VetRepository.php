<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Vet;

final class VetRepository extends AbstractRepository
{
    public function createProfile(int $userId, string $firstName, string $lastName, string $specialty): void
    {
        $this->execute(
            'INSERT INTO vets (user_id, first_name, last_name, specialty)
             VALUES (:user_id, :first_name, :last_name, :specialty)',
            [
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'specialty' => $specialty,
            ],
        );
    }

    public function findByUserId(int $userId): ?Vet
    {
        return $this->fetchOne(
            'SELECT id, user_id, first_name, last_name, specialty FROM vets WHERE user_id = :user_id',
            ['user_id' => $userId],
            $this->hydrate(...),
        );
    }

    public function findById(int $id): ?Vet
    {
        return $this->fetchOne(
            'SELECT id, user_id, first_name, last_name, specialty FROM vets WHERE id = :id',
            ['id' => $id],
            $this->hydrate(...),
        );
    }

    /**
     * @return list<Vet>
     */
    public function findAll(): array
    {
        return $this->fetchAll(
            'SELECT id, user_id, first_name, last_name, specialty FROM vets ORDER BY last_name, first_name',
            [],
            $this->hydrate(...),
        );
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

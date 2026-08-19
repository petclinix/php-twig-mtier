<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Owner;

final class OwnerRepository extends AbstractRepository
{
    public function createProfile(int $userId, string $firstName, string $lastName, string $phone, string $address): void
    {
        $this->execute(
            'INSERT INTO owners (user_id, first_name, last_name, phone, address)
             VALUES (:user_id, :first_name, :last_name, :phone, :address)',
            [
                'user_id' => $userId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'address' => $address,
            ],
        );
    }

    public function findByUserId(int $userId): ?Owner
    {
        return $this->fetchOne(
            'SELECT id, user_id, first_name, last_name, phone, address FROM owners WHERE user_id = :user_id',
            ['user_id' => $userId],
            $this->hydrate(...),
        );
    }

    public function findById(int $id): ?Owner
    {
        return $this->fetchOne(
            'SELECT id, user_id, first_name, last_name, phone, address FROM owners WHERE id = :id',
            ['id' => $id],
            $this->hydrate(...),
        );
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

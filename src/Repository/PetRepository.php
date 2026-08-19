<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Pet;
use DateTimeImmutable;

final class PetRepository extends AbstractRepository
{
    public function create(int $ownerId, string $name, string $species, ?string $breed, ?DateTimeImmutable $birthDate): Pet
    {
        $this->execute(
            'INSERT INTO pets (owner_id, name, species, breed, birth_date)
             VALUES (:owner_id, :name, :species, :breed, :birth_date)',
            [
                'owner_id' => $ownerId,
                'name' => $name,
                'species' => $species,
                'breed' => $breed,
                'birth_date' => $birthDate?->format('Y-m-d'),
            ],
        );

        return $this->findById($this->lastInsertId()) ?? throw PetPersistenceException::failedToLoadAfterInsert();
    }

    public function findById(int $id): ?Pet
    {
        return $this->fetchOne(
            'SELECT id, owner_id, name, species, breed, birth_date FROM pets WHERE id = :id',
            ['id' => $id],
            $this->hydrate(...),
        );
    }

    /**
     * @return list<Pet>
     */
    public function findAllByOwnerId(int $ownerId): array
    {
        return $this->fetchAll(
            'SELECT id, owner_id, name, species, breed, birth_date FROM pets WHERE owner_id = :owner_id ORDER BY name',
            ['owner_id' => $ownerId],
            $this->hydrate(...),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Pet
    {
        return new Pet(
            (int) $row['id'],
            (int) $row['owner_id'],
            (string) $row['name'],
            (string) $row['species'],
            $row['breed'] !== null ? (string) $row['breed'] : null,
            $row['birth_date'] !== null ? new DateTimeImmutable((string) $row['birth_date']) : null,
        );
    }
}

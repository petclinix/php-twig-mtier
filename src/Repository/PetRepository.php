<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Pet;
use App\Infrastructure\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class PetRepository
{
    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function create(int $ownerId, string $name, string $species, ?string $breed, ?DateTimeImmutable $birthDate): Pet
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pets (owner_id, name, species, breed, birth_date)
             VALUES (:owner_id, :name, :species, :breed, :birth_date)'
        );
        $stmt->execute([
            'owner_id' => $ownerId,
            'name' => $name,
            'species' => $species,
            'breed' => $breed,
            'birth_date' => $birthDate?->format('Y-m-d'),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id) ?? throw new RuntimeException('Failed to load pet after insert.');
    }

    public function findById(int $id): ?Pet
    {
        $stmt = $this->pdo->prepare('SELECT id, owner_id, name, species, breed, birth_date FROM pets WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return list<Pet>
     */
    public function findAllByOwnerId(int $ownerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, owner_id, name, species, breed, birth_date FROM pets WHERE owner_id = :owner_id ORDER BY name'
        );
        $stmt->execute(['owner_id' => $ownerId]);

        return array_map($this->hydrate(...), $stmt->fetchAll());
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

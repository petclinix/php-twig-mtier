<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Availability;
use App\Infrastructure\Database;
use DateTimeImmutable;
use PDO;

final class AvailabilityRepository
{
    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function create(int $vetId, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): Availability
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO availability (vet_id, starts_at, ends_at) VALUES (:vet_id, :starts_at, :ends_at)'
        );
        $stmt->execute([
            'vet_id' => $vetId,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->format('Y-m-d H:i:s'),
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id) ?? throw AvailabilityPersistenceException::failedToLoadAfterInsert();
    }

    public function findById(int $id): ?Availability
    {
        $stmt = $this->pdo->prepare('SELECT id, vet_id, starts_at, ends_at FROM availability WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return list<Availability>
     */
    public function findAllByVetId(int $vetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, vet_id, starts_at, ends_at FROM availability WHERE vet_id = :vet_id ORDER BY starts_at'
        );
        $stmt->execute(['vet_id' => $vetId]);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function delete(int $id, int $vetId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM availability WHERE id = :id AND vet_id = :vet_id');
        $stmt->execute(['id' => $id, 'vet_id' => $vetId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Availability
    {
        return new Availability(
            (int) $row['id'],
            (int) $row['vet_id'],
            new DateTimeImmutable((string) $row['starts_at']),
            new DateTimeImmutable((string) $row['ends_at']),
        );
    }
}

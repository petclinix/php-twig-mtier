<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Visit;
use App\Infrastructure\Database;
use DateTimeImmutable;
use PDO;

final class VisitRepository
{
    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function create(int $appointmentId, ?string $diagnosis, ?string $notes): Visit
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO visits (appointment_id, diagnosis, notes) VALUES (:appointment_id, :diagnosis, :notes)'
        );
        $stmt->execute([
            'appointment_id' => $appointmentId,
            'diagnosis' => $diagnosis,
            'notes' => $notes,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id) ?? throw VisitPersistenceException::failedToLoadAfterInsert();
    }

    public function findById(int $id): ?Visit
    {
        $stmt = $this->pdo->prepare('SELECT id, appointment_id, diagnosis, notes, recorded_at FROM visits WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param list<int> $petIds
     * @return list<Visit>
     */
    public function findAllByPetIds(array $petIds): array
    {
        if ($petIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($petIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT v.id, v.appointment_id, v.diagnosis, v.notes, v.recorded_at
             FROM visits v
             INNER JOIN appointments a ON a.id = v.appointment_id
             WHERE a.pet_id IN ($placeholders)
             ORDER BY v.recorded_at DESC"
        );
        $stmt->execute($petIds);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Visit
    {
        return new Visit(
            (int) $row['id'],
            (int) $row['appointment_id'],
            $row['diagnosis'] !== null ? (string) $row['diagnosis'] : null,
            $row['notes'] !== null ? (string) $row['notes'] : null,
            new DateTimeImmutable((string) $row['recorded_at']),
        );
    }
}

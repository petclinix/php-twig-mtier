<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Visit;
use DateTimeImmutable;

final class VisitRepository extends AbstractRepository
{
    public function create(int $appointmentId, ?string $diagnosis, ?string $notes): Visit
    {
        $this->execute(
            'INSERT INTO visits (appointment_id, diagnosis, notes) VALUES (:appointment_id, :diagnosis, :notes)',
            [
                'appointment_id' => $appointmentId,
                'diagnosis' => $diagnosis,
                'notes' => $notes,
            ],
        );

        return $this->findById($this->lastInsertId()) ?? throw VisitPersistenceException::failedToLoadAfterInsert();
    }

    public function findById(int $id): ?Visit
    {
        return $this->fetchOne(
            'SELECT id, appointment_id, diagnosis, notes, recorded_at FROM visits WHERE id = :id',
            ['id' => $id],
            $this->hydrate(...),
        );
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

        return $this->fetchAll(
            "SELECT v.id, v.appointment_id, v.diagnosis, v.notes, v.recorded_at
             FROM visits v
             INNER JOIN appointments a ON a.id = v.appointment_id
             WHERE a.pet_id IN ($placeholders)
             ORDER BY v.recorded_at DESC",
            $petIds,
            $this->hydrate(...),
        );
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

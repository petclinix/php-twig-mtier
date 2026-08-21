<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Repository\Exception\AppointmentPersistenceException;
use DateTimeImmutable;

final class AppointmentRepository extends AbstractRepository
{
    public function create(int $petId, int $vetId, DateTimeImmutable $scheduledAt, ?string $reason): Appointment
    {
        $this->execute(
            'INSERT INTO appointments (pet_id, vet_id, scheduled_at, reason)
             VALUES (:pet_id, :vet_id, :scheduled_at, :reason)',
            [
                'pet_id' => $petId,
                'vet_id' => $vetId,
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'reason' => $reason,
            ],
        );

        return $this->findById($this->lastInsertId()) ?? throw AppointmentPersistenceException::failedToLoadAfterInsert();
    }

    public function findById(int $id): ?Appointment
    {
        return $this->fetchOne(
            'SELECT id, pet_id, vet_id, scheduled_at, status, reason, created_at FROM appointments WHERE id = :id',
            ['id' => $id],
            $this->hydrate(...),
        );
    }

    /**
     * @param list<int> $petIds
     * @return list<Appointment>
     */
    public function findAllByPetIds(array $petIds): array
    {
        if ($petIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($petIds), '?'));

        return $this->fetchAll(
            "SELECT id, pet_id, vet_id, scheduled_at, status, reason, created_at
             FROM appointments WHERE pet_id IN ($placeholders) ORDER BY scheduled_at DESC",
            $petIds,
            $this->hydrate(...),
        );
    }

    /**
     * @return list<Appointment>
     */
    public function findAllByVetId(int $vetId): array
    {
        return $this->fetchAll(
            'SELECT id, pet_id, vet_id, scheduled_at, status, reason, created_at
             FROM appointments WHERE vet_id = :vet_id ORDER BY scheduled_at',
            ['vet_id' => $vetId],
            $this->hydrate(...),
        );
    }

    /**
     * @return list<Appointment>
     */
    public function findActiveByVetId(int $vetId): array
    {
        return $this->fetchAll(
            "SELECT id, pet_id, vet_id, scheduled_at, status, reason, created_at
             FROM appointments WHERE vet_id = :vet_id AND status IN ('requested', 'confirmed') ORDER BY scheduled_at",
            ['vet_id' => $vetId],
            $this->hydrate(...),
        );
    }

    public function updateStatus(int $id, AppointmentStatus $status): void
    {
        $this->execute(
            'UPDATE appointments SET status = :status WHERE id = :id',
            ['status' => $status->value, 'id' => $id],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Appointment
    {
        return new Appointment(
            (int) $row['id'],
            (int) $row['pet_id'],
            (int) $row['vet_id'],
            new DateTimeImmutable((string) $row['scheduled_at']),
            AppointmentStatus::from((string) $row['status']),
            $row['reason'] !== null ? (string) $row['reason'] : null,
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Infrastructure\Database;
use App\Repository\Exception\AppointmentPersistenceException;
use App\Repository\Exception\AppointmentSlotUnavailableException;
use DateTimeImmutable;
use PDOException;

final class AppointmentRepository extends AbstractRepository
{
    private const MYSQL_ERROR_DUPLICATE_ENTRY = 1062;

    public function create(int $petId, int $vetId, DateTimeImmutable $scheduledAt, ?string $reason, int $durationMinutes = 30): Appointment
    {
        return Database::runInTransaction(function () use ($petId, $vetId, $scheduledAt, $reason, $durationMinutes): Appointment {
            // Serialize all booking attempts for this vet so the overlap check
            // below and the subsequent insert are atomic together — required
            // once duration varies, since two bookings with different start
            // times can still overlap and the unique index on exact-match
            // slots (see schema.sql) can no longer catch that alone.
            $this->pdo->prepare('SELECT id FROM vets WHERE id = :vet_id FOR UPDATE')->execute(['vet_id' => $vetId]);

            $end = $scheduledAt->modify("+{$durationMinutes} minutes");
            if ($this->hasOverlap($vetId, $scheduledAt, $end)) {
                throw AppointmentSlotUnavailableException::alreadyBooked();
            }

            try {
                $this->execute(
                    'INSERT INTO appointments (pet_id, vet_id, scheduled_at, duration_minutes, reason)
                     VALUES (:pet_id, :vet_id, :scheduled_at, :duration_minutes, :reason)',
                    [
                        'pet_id' => $petId,
                        'vet_id' => $vetId,
                        'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                        'duration_minutes' => $durationMinutes,
                        'reason' => $reason,
                    ],
                );
            } catch (PDOException $e) {
                // Backstop only — the lock + overlap check above should already
                // have prevented this under normal operation.
                if ((int) ($e->errorInfo[1] ?? 0) === self::MYSQL_ERROR_DUPLICATE_ENTRY) {
                    throw AppointmentSlotUnavailableException::alreadyBooked();
                }

                throw $e;
            }

            return $this->findById($this->lastInsertId()) ?? throw AppointmentPersistenceException::failedToLoadAfterInsert();
        });
    }

    public function findById(int $id): ?Appointment
    {
        return $this->fetchOne(
            'SELECT id, pet_id, vet_id, scheduled_at, duration_minutes, status, reason, created_at FROM appointments WHERE id = :id',
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
            "SELECT id, pet_id, vet_id, scheduled_at, duration_minutes, status, reason, created_at
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
            'SELECT id, pet_id, vet_id, scheduled_at, duration_minutes, status, reason, created_at
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
            "SELECT id, pet_id, vet_id, scheduled_at, duration_minutes, status, reason, created_at
             FROM appointments WHERE vet_id = :vet_id AND status IN ('requested', 'confirmed') ORDER BY scheduled_at",
            ['vet_id' => $vetId],
            $this->hydrate(...),
        );
    }

    private function hasOverlap(int $vetId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM appointments
             WHERE vet_id = :vet_id AND status IN ('requested', 'confirmed')
               AND scheduled_at < :end AND scheduled_at + INTERVAL duration_minutes MINUTE > :start",
        );
        $stmt->execute([
            'vet_id' => $vetId,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ]);

        return ((int) $stmt->fetchColumn()) > 0;
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
            (int) $row['duration_minutes'],
            AppointmentStatus::from((string) $row['status']),
            $row['reason'] !== null ? (string) $row['reason'] : null,
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}

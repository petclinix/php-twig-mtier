<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Infrastructure\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class AppointmentRepository
{
    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function create(int $petId, int $vetId, DateTimeImmutable $scheduledAt, ?string $reason): Appointment
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO appointments (pet_id, vet_id, scheduled_at, reason)
             VALUES (:pet_id, :vet_id, :scheduled_at, :reason)'
        );
        $stmt->execute([
            'pet_id' => $petId,
            'vet_id' => $vetId,
            'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
            'reason' => $reason,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id) ?? throw new RuntimeException('Failed to load appointment after insert.');
    }

    public function findById(int $id): ?Appointment
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, pet_id, vet_id, scheduled_at, status, reason, created_at FROM appointments WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
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
        $stmt = $this->pdo->prepare(
            "SELECT id, pet_id, vet_id, scheduled_at, status, reason, created_at
             FROM appointments WHERE pet_id IN ($placeholders) ORDER BY scheduled_at DESC"
        );
        $stmt->execute($petIds);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    /**
     * @return list<Appointment>
     */
    public function findAllByVetId(int $vetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, pet_id, vet_id, scheduled_at, status, reason, created_at
             FROM appointments WHERE vet_id = :vet_id ORDER BY scheduled_at'
        );
        $stmt->execute(['vet_id' => $vetId]);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function updateStatus(int $id, AppointmentStatus $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE appointments SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status->value, 'id' => $id]);
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

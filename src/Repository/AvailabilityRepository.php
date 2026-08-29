<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Availability;
use App\Domain\DayOfWeek;
use App\Repository\Exception\AvailabilityPersistenceException;
use DateTimeImmutable;

final class AvailabilityRepository extends AbstractRepository
{
    public function create(int $vetId, DayOfWeek $dayOfWeek, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): Availability
    {
        $this->execute(
            'INSERT INTO availability (vet_id, day_of_week, starts_at, ends_at) VALUES (:vet_id, :day_of_week, :starts_at, :ends_at)',
            [
                'vet_id' => $vetId,
                'day_of_week' => $dayOfWeek->value,
                'starts_at' => $startsAt->format('H:i:s'),
                'ends_at' => $endsAt->format('H:i:s'),
            ],
        );

        return $this->findById($this->lastInsertId()) ?? throw AvailabilityPersistenceException::failedToLoadAfterInsert();
    }

    public function findById(int $id): ?Availability
    {
        return $this->fetchOne(
            'SELECT id, vet_id, day_of_week, starts_at, ends_at FROM availability WHERE id = :id',
            ['id' => $id],
            $this->hydrate(...),
        );
    }

    /**
     * @return list<Availability>
     */
    public function findAllByVetId(int $vetId): array
    {
        return $this->fetchAll(
            'SELECT id, vet_id, day_of_week, starts_at, ends_at FROM availability WHERE vet_id = :vet_id ORDER BY day_of_week, starts_at',
            ['vet_id' => $vetId],
            $this->hydrate(...),
        );
    }

    public function delete(int $id, int $vetId): void
    {
        $this->execute(
            'DELETE FROM availability WHERE id = :id AND vet_id = :vet_id',
            ['id' => $id, 'vet_id' => $vetId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Availability
    {
        return new Availability(
            (int) $row['id'],
            (int) $row['vet_id'],
            DayOfWeek::from((string) $row['day_of_week']),
            DateTimeImmutable::createFromFormat('!H:i:s', (string) $row['starts_at']),
            DateTimeImmutable::createFromFormat('!H:i:s', (string) $row['ends_at']),
        );
    }
}

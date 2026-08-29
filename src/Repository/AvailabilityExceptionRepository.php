<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\AvailabilityException;
use App\Repository\Exception\AvailabilityExceptionPersistenceException;
use DateTimeImmutable;

final class AvailabilityExceptionRepository extends AbstractRepository
{
    public function create(
        int $vetId,
        DateTimeImmutable $date,
        bool $isAvailable,
        ?DateTimeImmutable $startsAt,
        ?DateTimeImmutable $endsAt,
    ): AvailabilityException {
        $this->execute(
            'INSERT INTO availability_exceptions (vet_id, exception_date, is_available, starts_at, ends_at)
             VALUES (:vet_id, :exception_date, :is_available, :starts_at, :ends_at)',
            [
                'vet_id' => $vetId,
                'exception_date' => $date->format('Y-m-d'),
                'is_available' => $isAvailable ? 1 : 0,
                'starts_at' => $startsAt?->format('H:i:s'),
                'ends_at' => $endsAt?->format('H:i:s'),
            ],
        );

        return $this->findById($this->lastInsertId()) ?? throw AvailabilityExceptionPersistenceException::failedToLoadAfterInsert();
    }

    public function findById(int $id): ?AvailabilityException
    {
        return $this->fetchOne(
            'SELECT id, vet_id, exception_date, is_available, starts_at, ends_at FROM availability_exceptions WHERE id = :id',
            ['id' => $id],
            $this->hydrate(...),
        );
    }

    /**
     * @return list<AvailabilityException>
     */
    public function findAllByVetId(int $vetId): array
    {
        return $this->fetchAll(
            'SELECT id, vet_id, exception_date, is_available, starts_at, ends_at
             FROM availability_exceptions WHERE vet_id = :vet_id ORDER BY exception_date',
            ['vet_id' => $vetId],
            $this->hydrate(...),
        );
    }

    /**
     * @return list<AvailabilityException>
     */
    public function findUpcomingByVetId(int $vetId, DateTimeImmutable $from): array
    {
        return $this->fetchAll(
            'SELECT id, vet_id, exception_date, is_available, starts_at, ends_at
             FROM availability_exceptions WHERE vet_id = :vet_id AND exception_date >= :from ORDER BY exception_date',
            ['vet_id' => $vetId, 'from' => $from->format('Y-m-d')],
            $this->hydrate(...),
        );
    }

    public function delete(int $id, int $vetId): void
    {
        $this->execute(
            'DELETE FROM availability_exceptions WHERE id = :id AND vet_id = :vet_id',
            ['id' => $id, 'vet_id' => $vetId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AvailabilityException
    {
        return new AvailabilityException(
            (int) $row['id'],
            (int) $row['vet_id'],
            new DateTimeImmutable((string) $row['exception_date']),
            (bool) $row['is_available'],
            $row['starts_at'] !== null ? DateTimeImmutable::createFromFormat('!H:i:s', (string) $row['starts_at']) : null,
            $row['ends_at'] !== null ? DateTimeImmutable::createFromFormat('!H:i:s', (string) $row['ends_at']) : null,
        );
    }
}

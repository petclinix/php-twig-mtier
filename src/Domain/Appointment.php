<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final readonly class Appointment
{
    public function __construct(
        public int $id,
        public int $petId,
        public int $vetId,
        public DateTimeImmutable $scheduledAt,
        public int $durationMinutes,
        public AppointmentStatus $status,
        public ?string $reason,
        public DateTimeImmutable $createdAt,
    ) {
    }
}

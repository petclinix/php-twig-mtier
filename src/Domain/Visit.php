<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final readonly class Visit
{
    public function __construct(
        public int $id,
        public int $appointmentId,
        public ?string $diagnosis,
        public ?string $vaccination,
        public ?string $notes,
        public DateTimeImmutable $recordedAt,
    ) {}
}

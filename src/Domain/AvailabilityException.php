<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final readonly class AvailabilityException
{
    public function __construct(
        public int $id,
        public int $vetId,
        public DateTimeImmutable $date,
        public bool $isAvailable,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
    ) {}
}

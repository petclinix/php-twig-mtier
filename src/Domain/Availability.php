<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final readonly class Availability
{
    public function __construct(
        public int $id,
        public int $vetId,
        public DayOfWeek $dayOfWeek,
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
    ) {}
}

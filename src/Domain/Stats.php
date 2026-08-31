<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Stats
{
    /**
     * @param array<string, int> $appointmentsByStatus
     */
    public function __construct(
        public int $ownerCount,
        public int $vetCount,
        public int $petCount,
        public int $appointmentCount,
        public array $appointmentsByStatus,
        public int $visitCount,
    ) {}
}

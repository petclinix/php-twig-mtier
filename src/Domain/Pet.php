<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final readonly class Pet
{
    public function __construct(
        public int $id,
        public int $ownerId,
        public string $name,
        public string $species,
        public ?string $breed,
        public ?DateTimeImmutable $birthDate,
    ) {
    }
}

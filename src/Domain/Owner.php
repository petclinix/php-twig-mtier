<?php

declare(strict_types=1);

namespace App\Domain;

final readonly class Owner
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $firstName,
        public string $lastName,
        public string $phone,
        public string $address,
    ) {
    }
}

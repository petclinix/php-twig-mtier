<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $email,
        public string $passwordHash,
        public Role $role,
        public bool $isActive,
        public DateTimeImmutable $createdAt,
    ) {}
}

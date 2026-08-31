<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final readonly class ActivityLogEntry
{
    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        public int $id,
        public ?int $userId,
        public string $action,
        public ?array $context,
        public DateTimeImmutable $createdAt,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

final class MiddlewareResult
{
    private function __construct(
        public readonly bool $continue,
        public readonly ?int $statusCode = null,
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    public static function stop(int $statusCode): self
    {
        return new self(false, $statusCode);
    }
}

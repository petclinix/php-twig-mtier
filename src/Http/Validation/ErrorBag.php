<?php

declare(strict_types=1);

namespace App\Http\Validation;

final class ErrorBag
{
    /** @var list<string> */
    private array $errors = [];

    public function add(string $message): void
    {
        $this->errors[] = $message;
    }

    public function addIf(bool $condition, string $message): void
    {
        if ($condition) {
            $this->errors[] = $message;
        }
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->errors;
    }
}

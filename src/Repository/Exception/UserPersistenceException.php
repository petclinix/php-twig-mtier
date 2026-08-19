<?php

declare(strict_types=1);

namespace App\Repository\Exception;

use RuntimeException;

final class UserPersistenceException extends RuntimeException
{
    public static function failedToLoadAfterInsert(): self
    {
        return new self('Failed to load user after insert.');
    }
}

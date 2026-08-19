<?php

declare(strict_types=1);

namespace App\Repository\Exception;

use RuntimeException;

final class PetPersistenceException extends RuntimeException
{
    public static function failedToLoadAfterInsert(): self
    {
        return new self('Failed to load pet after insert.');
    }
}

<?php

declare(strict_types=1);

namespace App\Repository;

use RuntimeException;

final class VisitPersistenceException extends RuntimeException
{
    public static function failedToLoadAfterInsert(): self
    {
        return new self('Failed to load visit after insert.');
    }
}

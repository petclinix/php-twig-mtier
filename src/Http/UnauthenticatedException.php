<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

final class UnauthenticatedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No authenticated user.');
    }
}

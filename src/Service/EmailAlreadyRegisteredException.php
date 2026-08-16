<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class EmailAlreadyRegisteredException extends RuntimeException
{
    public function __construct(string $email)
    {
        parent::__construct(sprintf('Email "%s" is already registered.', $email));
    }
}

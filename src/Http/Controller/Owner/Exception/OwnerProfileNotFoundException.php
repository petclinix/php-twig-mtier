<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner\Exception;

use RuntimeException;

final class OwnerProfileNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Owner profile not found for current user.');
    }
}

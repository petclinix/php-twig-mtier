<?php

declare(strict_types=1);

namespace App\Http\Controller\Vet;

use RuntimeException;

final class VetProfileNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Vet profile not found for current user.');
    }
}

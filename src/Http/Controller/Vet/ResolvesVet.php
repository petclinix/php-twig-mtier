<?php

declare(strict_types=1);

namespace App\Http\Controller\Vet;

use App\Domain\Vet;
use App\Http\Session;
use App\Repository\VetRepository;
use RuntimeException;

trait ResolvesVet
{
    private function currentVet(): Vet
    {
        $userId = Session::userId() ?? throw new RuntimeException('No authenticated user.');

        return (new VetRepository())->findByUserId($userId)
            ?? throw new RuntimeException('Vet profile not found for current user.');
    }
}

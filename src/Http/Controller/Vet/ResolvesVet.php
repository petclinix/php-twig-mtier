<?php

declare(strict_types=1);

namespace App\Http\Controller\Vet;

use App\Domain\Vet;
use App\Http\Controller\Vet\Exception\VetProfileNotFoundException;
use App\Http\Exception\UnauthenticatedException;
use App\Http\Session;
use App\Repository\VetRepository;

trait ResolvesVet
{
    private function currentVet(): Vet
    {
        $userId = Session::userId() ?? throw new UnauthenticatedException();

        return (new VetRepository())->findByUserId($userId)
            ?? throw new VetProfileNotFoundException();
    }
}

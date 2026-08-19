<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Domain\Owner;
use App\Http\Controller\Owner\Exception\OwnerProfileNotFoundException;
use App\Http\Exception\UnauthenticatedException;
use App\Http\Session;
use App\Repository\OwnerRepository;

trait ResolvesOwner
{
    private function currentOwner(): Owner
    {
        $userId = Session::userId() ?? throw new UnauthenticatedException();

        return (new OwnerRepository())->findByUserId($userId)
            ?? throw new OwnerProfileNotFoundException();
    }
}

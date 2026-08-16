<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Domain\Owner;
use App\Http\Session;
use App\Repository\OwnerRepository;
use RuntimeException;

trait ResolvesOwner
{
    private function currentOwner(): Owner
    {
        $userId = Session::userId() ?? throw new RuntimeException('No authenticated user.');

        return (new OwnerRepository())->findByUserId($userId)
            ?? throw new RuntimeException('Owner profile not found for current user.');
    }
}

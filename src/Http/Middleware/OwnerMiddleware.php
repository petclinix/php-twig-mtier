<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Role;
use App\Http\Session;

final class OwnerMiddleware implements Middleware
{
    public function handle(): bool
    {
        if (Session::role() === Role::Owner->value) {
            return true;
        }

        header('Location: /dashboard');

        return false;
    }
}

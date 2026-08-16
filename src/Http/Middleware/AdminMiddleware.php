<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Role;
use App\Http\Session;

final class AdminMiddleware implements Middleware
{
    public function handle(): bool
    {
        if (Session::role() === Role::Admin->value) {
            return true;
        }

        header('Location: /dashboard');

        return false;
    }
}

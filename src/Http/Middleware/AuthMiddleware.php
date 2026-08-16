<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Session;

final class AuthMiddleware implements Middleware
{
    public function handle(): bool
    {
        if (Session::isAuthenticated()) {
            return true;
        }

        header('Location: /login');

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Session;

final class AuthMiddleware implements Middleware
{
    public function handle(): MiddlewareResult
    {
        if (Session::isAuthenticated()) {
            return MiddlewareResult::pass();
        }

        header('Location: /login');

        return MiddlewareResult::stop(302);
    }
}

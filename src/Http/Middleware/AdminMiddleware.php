<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Role;
use App\Http\Session;

final class AdminMiddleware implements Middleware
{
    public function handle(): MiddlewareResult
    {
        if (Session::role() === Role::Admin->value) {
            return MiddlewareResult::pass();
        }

        header('Location: /dashboard');

        return MiddlewareResult::stop(302);
    }
}

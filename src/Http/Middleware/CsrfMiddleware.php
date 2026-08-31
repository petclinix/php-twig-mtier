<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Csrf;
use App\Http\Validation\Input;

final class CsrfMiddleware implements Middleware
{
    public function handle(): MiddlewareResult
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return MiddlewareResult::pass();
        }

        if (!Csrf::verify(Input::raw('_token'))) {
            return MiddlewareResult::stop(403);
        }

        return MiddlewareResult::pass();
    }
}

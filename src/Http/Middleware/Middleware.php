<?php

declare(strict_types=1);

namespace App\Http\Middleware;

interface Middleware
{
    /**
     * Returns MiddlewareResult::pass() to let the request continue to the
     * controller, or MiddlewareResult::stop($statusCode) if it has already
     * short-circuited the response (e.g. a redirect) with that status code.
     */
    public function handle(): MiddlewareResult;
}

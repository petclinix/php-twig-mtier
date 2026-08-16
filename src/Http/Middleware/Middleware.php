<?php

declare(strict_types=1);

namespace App\Http\Middleware;

interface Middleware
{
    /**
     * Returns true to let the request continue to the controller.
     * Returns false if it has already short-circuited the response (e.g. a redirect).
     */
    public function handle(): bool;
}

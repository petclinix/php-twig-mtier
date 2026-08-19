<?php

declare(strict_types=1);

namespace App\Tests\Support\Router;

use App\Http\Middleware\Middleware;

final class BlockingMiddleware implements Middleware
{
    public function handle(): bool
    {
        MiddlewareCallLog::$calls[] = self::class;

        return false;
    }
}

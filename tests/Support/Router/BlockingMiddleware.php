<?php

declare(strict_types=1);

namespace App\Tests\Support\Router;

use App\Http\Middleware\Middleware;
use App\Http\Middleware\MiddlewareResult;

final class BlockingMiddleware implements Middleware
{
    public function handle(): MiddlewareResult
    {
        MiddlewareCallLog::$calls[] = self::class;

        return MiddlewareResult::stop(403);
    }
}

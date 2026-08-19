<?php

declare(strict_types=1);

namespace App\Tests\Support\Router;

final class MiddlewareCallLog
{
    /** @var list<class-string> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }
}

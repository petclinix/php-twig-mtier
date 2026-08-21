<?php

declare(strict_types=1);

namespace App\Http\Validation;

final class Input
{
    public static function string(string $key, string $default = ''): string
    {
        return trim(self::raw($key, $default));
    }

    public static function raw(string $key, string $default = ''): string
    {
        return (string) ($_POST[$key] ?? $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) ($_POST[$key] ?? $default);
    }

    public static function query(string $key, string $default = ''): string
    {
        return trim(self::rawQuery($key, $default));
    }

    public static function rawQuery(string $key, string $default = ''): string
    {
        return (string) ($_GET[$key] ?? $default);
    }

    public static function queryInt(string $key, int $default = 0): int
    {
        return (int) ($_GET[$key] ?? $default);
    }
}

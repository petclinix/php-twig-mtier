<?php

declare(strict_types=1);

namespace App\Http;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function verify(string $submitted): bool
    {
        if (empty($_SESSION[self::SESSION_KEY]) || $submitted === '') {
            return false;
        }

        return hash_equals($_SESSION[self::SESSION_KEY], $submitted);
    }
}

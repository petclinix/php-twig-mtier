<?php

declare(strict_types=1);

namespace App\Tests\Support;

final class HeaderSpy
{
    /** @var list<string> */
    public static array $headers = [];

    public static function record(string $header): void
    {
        self::$headers[] = $header;
    }

    public static function reset(): void
    {
        self::$headers = [];
    }

    public static function location(): ?string
    {
        foreach (self::$headers as $header) {
            if (stripos($header, 'Location:') === 0) {
                return trim(substr($header, strlen('Location:')));
            }
        }

        return null;
    }
}

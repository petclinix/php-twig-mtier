<?php

declare(strict_types=1);

namespace App\Http\Validation;

use DateTimeImmutable;

final class Validate
{
    public static function date(string $input, string $format): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat($format, $input);

        return $date !== false ? $date : null;
    }
}

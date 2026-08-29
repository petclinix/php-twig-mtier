<?php

declare(strict_types=1);

namespace App\Repository\Exception;

use RuntimeException;

final class AppointmentSlotUnavailableException extends RuntimeException
{
    public static function alreadyBooked(): self
    {
        return new self('That appointment slot is no longer available.');
    }
}

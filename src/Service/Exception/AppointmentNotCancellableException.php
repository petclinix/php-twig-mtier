<?php

declare(strict_types=1);

namespace App\Service\Exception;

use RuntimeException;

final class AppointmentNotCancellableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This appointment can no longer be cancelled or rescheduled.');
    }
}

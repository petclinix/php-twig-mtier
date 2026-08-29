<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

enum DayOfWeek: string
{
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';

    public static function fromDate(DateTimeImmutable $date): self
    {
        return self::from(strtolower($date->format('l')));
    }
}

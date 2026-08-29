<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\AvailabilityException;
use App\Repository\AvailabilityExceptionRepository;
use DateTimeImmutable;

trait CreatesTestAvailability
{
    /**
     * Convenience for tests that just need "the vet has an open window
     * covering this specific moment," with no recurrence — implemented as a
     * one-off custom-hours availability exception (not a weekly template,
     * which would recur on every matching weekday within the service's
     * lookahead window and inflate slot counts beyond what the test expects).
     */
    private function createAvailabilityWindow(int $vetId, DateTimeImmutable $start, DateTimeImmutable $end): AvailabilityException
    {
        return (new AvailabilityExceptionRepository())->create($vetId, $start, true, $start, $end);
    }
}

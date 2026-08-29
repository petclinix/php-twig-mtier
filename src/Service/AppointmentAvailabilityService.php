<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Appointment;
use App\Domain\Availability;
use App\Repository\AppointmentRepository;
use App\Repository\AvailabilityRepository;
use DateInterval;
use DateTimeImmutable;

final class AppointmentAvailabilityService
{
    /** Grid granularity for candidate start times — not the appointment length. */
    private const SLOT_STEP_MINUTES = 30;
    private const MAX_SLOTS = 50;

    public function __construct(
        private readonly AvailabilityRepository $availability,
        private readonly AppointmentRepository $appointments,
    ) {
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function openSlots(int $vetId, int $durationMinutes): array
    {
        $now = new DateTimeImmutable('now');

        /** @var list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $booked */
        $booked = array_map(
            static fn (Appointment $appointment): array => [
                $appointment->scheduledAt,
                $appointment->scheduledAt->modify('+' . $appointment->durationMinutes . ' minutes'),
            ],
            $this->appointments->findActiveByVetId($vetId),
        );

        $slots = [];
        foreach ($this->availability->findAllByVetId($vetId) as $window) {
            foreach ($this->candidatesInWindow($window, $now, $durationMinutes) as $key => $candidate) {
                if (isset($slots[$key])) {
                    continue;
                }

                if (count($slots) >= self::MAX_SLOTS) {
                    break 2;
                }

                if (!$this->overlapsAny($candidate, $durationMinutes, $booked)) {
                    $slots[$key] = $candidate;
                }
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /**
     * @return iterable<string, DateTimeImmutable>
     */
    private function candidatesInWindow(Availability $window, DateTimeImmutable $now, int $durationMinutes): iterable
    {
        $step = new DateInterval('PT' . self::SLOT_STEP_MINUTES . 'M');
        $duration = new DateInterval('PT' . $durationMinutes . 'M');
        $current = $window->startsAt;

        if ($current < $now) {
            $elapsedMinutes = ($now->getTimestamp() - $current->getTimestamp()) / 60;
            $steps = (int) ceil($elapsedMinutes / self::SLOT_STEP_MINUTES);
            $current = $current->add(new DateInterval('PT' . ($steps * self::SLOT_STEP_MINUTES) . 'M'));
        }

        while ($current->add($duration) <= $window->endsAt) {
            yield $current->format('Y-m-d H:i') => $current;
            $current = $current->add($step);
        }
    }

    /**
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $bookedIntervals
     */
    private function overlapsAny(DateTimeImmutable $start, int $durationMinutes, array $bookedIntervals): bool
    {
        $end = $start->modify('+' . $durationMinutes . ' minutes');

        foreach ($bookedIntervals as [$bookedStart, $bookedEnd]) {
            if ($start < $bookedEnd && $bookedStart < $end) {
                return true;
            }
        }

        return false;
    }
}

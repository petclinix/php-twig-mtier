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
    private const SLOT_MINUTES = 30;
    private const MAX_SLOTS = 50;

    public function __construct(
        private readonly AvailabilityRepository $availability,
        private readonly AppointmentRepository $appointments,
    ) {
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function openSlots(int $vetId): array
    {
        $now = new DateTimeImmutable('now');

        /** @var list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $booked */
        $booked = array_map(
            static fn (Appointment $appointment): array => [
                $appointment->scheduledAt,
                $appointment->scheduledAt->modify('+' . self::SLOT_MINUTES . ' minutes'),
            ],
            $this->appointments->findActiveByVetId($vetId),
        );

        $slots = [];
        foreach ($this->availability->findAllByVetId($vetId) as $window) {
            foreach ($this->candidatesInWindow($window, $now) as $key => $candidate) {
                if (isset($slots[$key])) {
                    continue;
                }

                if (count($slots) >= self::MAX_SLOTS) {
                    break 2;
                }

                if (!$this->overlapsAny($candidate, $booked)) {
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
    private function candidatesInWindow(Availability $window, DateTimeImmutable $now): iterable
    {
        $step = new DateInterval('PT' . self::SLOT_MINUTES . 'M');
        $current = $window->startsAt;

        if ($current < $now) {
            $elapsedMinutes = ($now->getTimestamp() - $current->getTimestamp()) / 60;
            $steps = (int) ceil($elapsedMinutes / self::SLOT_MINUTES);
            $current = $current->add(new DateInterval('PT' . ($steps * self::SLOT_MINUTES) . 'M'));
        }

        while ($current->add($step) <= $window->endsAt) {
            yield $current->format('Y-m-d H:i') => $current;
            $current = $current->add($step);
        }
    }

    /**
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $bookedIntervals
     */
    private function overlapsAny(DateTimeImmutable $start, array $bookedIntervals): bool
    {
        $end = $start->modify('+' . self::SLOT_MINUTES . ' minutes');

        foreach ($bookedIntervals as [$bookedStart, $bookedEnd]) {
            if ($start < $bookedEnd && $bookedStart < $end) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Appointment;
use App\Domain\Availability;
use App\Domain\AvailabilityException;
use App\Domain\DayOfWeek;
use App\Repository\AppointmentRepository;
use App\Repository\AvailabilityExceptionRepository;
use App\Repository\AvailabilityRepository;
use DateInterval;
use DateTimeImmutable;

final class AppointmentAvailabilityService
{
    /** Grid granularity for candidate start times — not the appointment length. */
    private const SLOT_STEP_MINUTES = 30;
    private const MAX_SLOTS = 50;
    private const SLOT_FORMAT = 'Y-m-d\TH:i';
    /** Booking horizon: how many days ahead recurring availability is projected. */
    private const LOOKAHEAD_DAYS = 60;

    public function __construct(
        private readonly AvailabilityRepository $availability,
        private readonly AvailabilityExceptionRepository $exceptions,
        private readonly AppointmentRepository $appointments,
    ) {
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function openSlots(int $vetId, int $durationMinutes): array
    {
        $now = new DateTimeImmutable('now');
        $today = $now->setTime(0, 0);

        /** @var list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $booked */
        $booked = array_map(
            static fn (Appointment $appointment): array => [
                $appointment->scheduledAt,
                $appointment->scheduledAt->modify('+' . $appointment->durationMinutes . ' minutes'),
            ],
            $this->appointments->findActiveByVetId($vetId),
        );

        /** @var array<string, list<Availability>> $templatesByDay */
        $templatesByDay = [];
        foreach ($this->availability->findAllByVetId($vetId) as $template) {
            $templatesByDay[$template->dayOfWeek->value][] = $template;
        }

        /** @var array<string, AvailabilityException> $exceptionsByDate */
        $exceptionsByDate = [];
        foreach ($this->exceptions->findUpcomingByVetId($vetId, $today) as $exception) {
            $exceptionsByDate[$exception->date->format('Y-m-d')] = $exception;
        }

        $slots = [];
        for ($offset = 0; $offset < self::LOOKAHEAD_DAYS; $offset++) {
            $date = $today->modify("+{$offset} days");

            foreach ($this->windowsForDate($date, $templatesByDay, $exceptionsByDate) as [$windowStart, $windowEnd]) {
                foreach ($this->candidatesInWindow($windowStart, $windowEnd, $now, $durationMinutes) as $key => $candidate) {
                    if (isset($slots[$key])) {
                        continue;
                    }

                    if (count($slots) >= self::MAX_SLOTS) {
                        break 3;
                    }

                    if (!$this->overlapsAny($candidate, $durationMinutes, $booked)) {
                        $slots[$key] = $candidate;
                    }
                }
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    public function isOfferedSlot(int $vetId, DateTimeImmutable $scheduledAt, int $durationMinutes): bool
    {
        $target = $scheduledAt->format(self::SLOT_FORMAT);

        foreach ($this->openSlots($vetId, $durationMinutes) as $slot) {
            if ($slot->format(self::SLOT_FORMAT) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * An exception for the exact date fully determines that day: unavailable
     * blocks it entirely, available *replaces* the day's hours with its own
     * (not additive to the weekly template). Otherwise the weekly template
     * for that day-of-week applies.
     *
     * @param array<string, list<Availability>> $templatesByDay
     * @param array<string, AvailabilityException> $exceptionsByDate
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    private function windowsForDate(DateTimeImmutable $date, array $templatesByDay, array $exceptionsByDate): array
    {
        $exception = $exceptionsByDate[$date->format('Y-m-d')] ?? null;

        if ($exception !== null) {
            if (!$exception->isAvailable || $exception->startsAt === null || $exception->endsAt === null) {
                return [];
            }

            return [[$this->combine($date, $exception->startsAt), $this->combine($date, $exception->endsAt)]];
        }

        $templates = $templatesByDay[DayOfWeek::fromDate($date)->value] ?? [];

        return array_map(
            fn (Availability $template): array => [$this->combine($date, $template->startsAt), $this->combine($date, $template->endsAt)],
            $templates,
        );
    }

    private function combine(DateTimeImmutable $date, DateTimeImmutable $time): DateTimeImmutable
    {
        return $date->setTime((int) $time->format('H'), (int) $time->format('i'), (int) $time->format('s'));
    }

    /**
     * @return iterable<string, DateTimeImmutable>
     */
    private function candidatesInWindow(DateTimeImmutable $windowStart, DateTimeImmutable $windowEnd, DateTimeImmutable $now, int $durationMinutes): iterable
    {
        $step = new DateInterval('PT' . self::SLOT_STEP_MINUTES . 'M');
        $duration = new DateInterval('PT' . $durationMinutes . 'M');
        $current = $windowStart;

        if ($current < $now) {
            $elapsedMinutes = ($now->getTimestamp() - $current->getTimestamp()) / 60;
            $steps = (int) ceil($elapsedMinutes / self::SLOT_STEP_MINUTES);
            $current = $current->add(new DateInterval('PT' . ($steps * self::SLOT_STEP_MINUTES) . 'M'));
        }

        while ($current->add($duration) <= $windowEnd) {
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

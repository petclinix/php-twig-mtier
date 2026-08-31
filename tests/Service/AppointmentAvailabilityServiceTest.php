<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\AppointmentStatus;
use App\Domain\DayOfWeek;
use App\Domain\Owner;
use App\Domain\Vet;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\AvailabilityExceptionRepository;
use App\Repository\AvailabilityRepository;
use App\Repository\PetRepository;
use App\Service\AppointmentAvailabilityService;
use App\Tests\Support\CreatesTestAvailability;
use App\Tests\Support\CreatesTestUsers;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AppointmentAvailabilityServiceTest extends TestCase
{
    use CreatesTestUsers;
    use CreatesTestAvailability;

    private AppointmentAvailabilityService $service;
    private AvailabilityRepository $availability;
    private AvailabilityExceptionRepository $exceptions;
    private AppointmentRepository $appointments;
    private string $ownerEmail;
    private string $vetEmail;
    private Owner $owner;
    private Vet $vet;
    private int $petId;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "avail-owner-{$suffix}@example.test";
        $this->vetEmail = "avail-vet-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->petId = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null)->id;

        $this->availability = new AvailabilityRepository();
        $this->exceptions = new AvailabilityExceptionRepository();
        $this->appointments = new AppointmentRepository();
        $this->service = new AppointmentAvailabilityService($this->availability, $this->exceptions, $this->appointments);
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
    }

    /**
     * Availability rows round-trip through the DB with second precision only
     * (see AvailabilityRepository::hydrate), so fixtures are built the same
     * way to keep assertEquals() comparisons against hydrated slots exact.
     */
    private function instant(string $modifier): DateTimeImmutable
    {
        return new DateTimeImmutable((new DateTimeImmutable($modifier))->format('Y-m-d H:i:s'));
    }

    public function testOpenSlotsReturnsEmptyWhenNoAvailability(): void
    {
        //act
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert
        self::assertSame([], $slots);
    }

    public function testOpenSlotsGeneratesThirtyMinuteIncrementsWithinWindow(): void
    {
        //arrange
        $start = $this->instant('+2 weeks');
        $this->createAvailabilityWindow($this->vet->id, $start, $start->modify('+90 minutes'));

        //act
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert
        self::assertCount(3, $slots);
        self::assertEquals($start, $slots[0]);
        self::assertEquals($start->modify('+30 minutes'), $slots[1]);
        self::assertEquals($start->modify('+60 minutes'), $slots[2]);
    }

    public function testOpenSlotsExcludesWindowRemainderThatDoesNotFitFullSlot(): void
    {
        //arrange
        $start = $this->instant('+2 weeks');
        $this->createAvailabilityWindow($this->vet->id, $start, $start->modify('+100 minutes'));

        //act
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert
        self::assertCount(3, $slots);
        self::assertEquals($start->modify('+60 minutes'), $slots[2]);
    }

    public function testOpenSlotsExcludesSlotsOverlappingActiveAppointment(): void
    {
        //arrange
        $start = $this->instant('+2 weeks');
        $this->createAvailabilityWindow($this->vet->id, $start, $start->modify('+90 minutes'));
        $this->appointments->create($this->petId, $this->vet->id, $start->modify('+30 minutes'), null);

        //act
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert
        self::assertCount(2, $slots);
        self::assertEquals($start, $slots[0]);
        self::assertEquals($start->modify('+60 minutes'), $slots[1]);
    }

    public function testOpenSlotsIncludesSlotsWhereConflictingAppointmentIsCancelled(): void
    {
        //arrange
        $start = $this->instant('+2 weeks');
        $this->createAvailabilityWindow($this->vet->id, $start, $start->modify('+90 minutes'));
        $appointment = $this->appointments->create($this->petId, $this->vet->id, $start->modify('+30 minutes'), null);
        $this->appointments->updateStatus($appointment->id, AppointmentStatus::Cancelled);

        //act
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert
        self::assertCount(3, $slots);
    }

    public function testOpenSlotsExcludesPastPortionOfWindowButKeepsGridAlignment(): void
    {
        //arrange
        $now = new DateTimeImmutable('now');
        $windowStart = $now->modify('-45 minutes');
        $windowEnd = $now->modify('+2 hours');
        $this->createAvailabilityWindow($this->vet->id, $windowStart, $windowEnd);

        //act
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert
        self::assertNotEmpty($slots);
        foreach ($slots as $slot) {
            self::assertGreaterThanOrEqual($now->getTimestamp(), $slot->getTimestamp());
            self::assertSame(0, ($slot->getTimestamp() - $windowStart->getTimestamp()) % 1800);
        }
    }

    public function testOpenSlotsCapsAtMaximumSlotCount(): void
    {
        //arrange — wide-open recurring availability every day of the week, so
        // the 60-day lookahead offers far more than 50 candidate slots
        foreach (DayOfWeek::cases() as $day) {
            $this->availability->create($this->vet->id, $day, new DateTimeImmutable('00:00'), new DateTimeImmutable('23:30'));
        }

        //act
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert
        self::assertCount(50, $slots);
    }

    public function testOpenSlotsIsScopedToRequestedVetOnly(): void
    {
        //arrange
        $otherVetEmail = 'avail-other-vet-' . bin2hex(random_bytes(6)) . '@example.test';
        $otherVet = $this->registerVet($otherVetEmail);

        $start = $this->instant('+2 weeks');
        $this->createAvailabilityWindow($this->vet->id, $start, $start->modify('+30 minutes'));
        $this->createAvailabilityWindow($otherVet->id, $start->modify('+5 hours'), $start->modify('+5 hours 30 minutes'));

        try {
            //act
            $slots = $this->service->openSlots($this->vet->id, 30);

            //assert
            self::assertCount(1, $slots);
            self::assertEquals($start, $slots[0]);
        } finally {
            Database::connection()
                ->prepare('DELETE FROM users WHERE email = :email')
                ->execute(['email' => $otherVetEmail]);
        }
    }

    public function testOpenSlotsUsesEachBookedAppointmentsOwnDuration(): void
    {
        //arrange — a 2-hour window with a 60-minute booking starting mid-window
        $start = $this->instant('+2 weeks');
        $this->createAvailabilityWindow($this->vet->id, $start, $start->modify('+2 hours'));
        $this->appointments->create($this->petId, $this->vet->id, $start->modify('+30 minutes'), null, 60);

        //act — request 30-minute slots
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert — the 60-minute booking occupies 10:30-11:30, so the 11:00 slot
        // must be excluded too, not just the one matching its own start time
        // (a fixed-30-minute assumption would have wrongly offered 11:00)
        self::assertCount(2, $slots);
        self::assertEquals($start, $slots[0]);
        self::assertEquals($start->modify('+90 minutes'), $slots[1]);
    }

    public function testOpenSlotsExcludesCandidatesThatDoNotFitRequestedDuration(): void
    {
        //arrange — a 90-minute window
        $start = $this->instant('+2 weeks');
        $this->createAvailabilityWindow($this->vet->id, $start, $start->modify('+90 minutes'));

        //act — request 60-minute slots
        $slots = $this->service->openSlots($this->vet->id, 60);

        //assert — only two 60-minute slots fit in a 90-minute window; the
        // grid-final 11:00 start wouldn't leave room for a 60-minute appointment
        self::assertCount(2, $slots);
        self::assertEquals($start, $slots[0]);
        self::assertEquals($start->modify('+30 minutes'), $slots[1]);
    }

    public function testIsOfferedSlotMatchesOpenSlot(): void
    {
        //arrange
        $start = $this->instant('+2 weeks');
        $this->createAvailabilityWindow($this->vet->id, $start, $start->modify('+30 minutes'));

        //act + assert
        self::assertTrue($this->service->isOfferedSlot($this->vet->id, $start, 30));
        self::assertFalse($this->service->isOfferedSlot($this->vet->id, $start->modify('+1 day'), 30));
    }

    public function testOpenSlotsExcludesDateBlockedByException(): void
    {
        //arrange — a weekly template that would normally offer slots every week
        $start = $this->instant('+2 weeks');
        $day = DayOfWeek::fromDate($start);
        $this->availability->create($this->vet->id, $day, new DateTimeImmutable('09:00'), new DateTimeImmutable('11:00'));
        $this->exceptions->create($this->vet->id, $start, false, null, null);

        //act
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert — none of the offered slots fall on the blocked date, even
        // though every other matching weekday within the lookahead does
        $blockedDate = $start->format('Y-m-d');
        foreach ($slots as $slot) {
            self::assertNotSame($blockedDate, $slot->format('Y-m-d'));
        }
        self::assertNotEmpty($slots);
    }

    public function testOpenSlotsOffersCustomHoursExceptionOnDayWithNoTemplate(): void
    {
        //arrange — no weekly template at all, only a one-off exception
        $start = $this->instant('+2 weeks');
        $this->exceptions->create($this->vet->id, $start, true, $start, $start->modify('+60 minutes'));

        //act
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert
        self::assertCount(2, $slots);
        self::assertEquals($start, $slots[0]);
        self::assertEquals($start->modify('+30 minutes'), $slots[1]);
    }

    public function testOpenSlotsExceptionReplacesRatherThanAddsToTemplateHours(): void
    {
        //arrange — a weekly template offering 09:00-11:00, overridden on one
        // specific date by a narrower custom-hours exception
        $start = $this->instant('+2 weeks')->setTime(9, 0);
        $day = DayOfWeek::fromDate($start);
        $this->availability->create($this->vet->id, $day, new DateTimeImmutable('09:00'), new DateTimeImmutable('11:00'));
        $exceptionStart = $start->setTime(14, 0);
        $this->exceptions->create($this->vet->id, $start, true, $exceptionStart, $exceptionStart->modify('+30 minutes'));

        //act
        $slots = $this->service->openSlots($this->vet->id, 30);

        //assert — only the exception's own hours are offered on that date;
        // the template's normal 09:00-11:00 hours do not also appear
        $onException = array_values(array_filter($slots, static fn(DateTimeImmutable $slot): bool => $slot->format('Y-m-d') === $start->format('Y-m-d')));
        self::assertCount(1, $onException);
        self::assertEquals($exceptionStart, $onException[0]);
    }
}

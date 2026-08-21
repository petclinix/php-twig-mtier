<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\AppointmentStatus;
use App\Domain\Owner;
use App\Domain\Vet;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\AvailabilityRepository;
use App\Repository\PetRepository;
use App\Service\AppointmentAvailabilityService;
use App\Tests\Support\CreatesTestUsers;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AppointmentAvailabilityServiceTest extends TestCase
{
    use CreatesTestUsers;

    private AppointmentAvailabilityService $service;
    private AvailabilityRepository $availability;
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
        $this->appointments = new AppointmentRepository();
        $this->service = new AppointmentAvailabilityService($this->availability, $this->appointments);
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
        $slots = $this->service->openSlots($this->vet->id);

        //assert
        self::assertSame([], $slots);
    }

    public function testOpenSlotsGeneratesThirtyMinuteIncrementsWithinWindow(): void
    {
        //arrange
        $start = $this->instant('+2 weeks');
        $this->availability->create($this->vet->id, $start, $start->modify('+90 minutes'));

        //act
        $slots = $this->service->openSlots($this->vet->id);

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
        $this->availability->create($this->vet->id, $start, $start->modify('+100 minutes'));

        //act
        $slots = $this->service->openSlots($this->vet->id);

        //assert
        self::assertCount(3, $slots);
        self::assertEquals($start->modify('+60 minutes'), $slots[2]);
    }

    public function testOpenSlotsExcludesSlotsOverlappingActiveAppointment(): void
    {
        //arrange
        $start = $this->instant('+2 weeks');
        $this->availability->create($this->vet->id, $start, $start->modify('+90 minutes'));
        $this->appointments->create($this->petId, $this->vet->id, $start->modify('+30 minutes'), null);

        //act
        $slots = $this->service->openSlots($this->vet->id);

        //assert
        self::assertCount(2, $slots);
        self::assertEquals($start, $slots[0]);
        self::assertEquals($start->modify('+60 minutes'), $slots[1]);
    }

    public function testOpenSlotsIncludesSlotsWhereConflictingAppointmentIsCancelled(): void
    {
        //arrange
        $start = $this->instant('+2 weeks');
        $this->availability->create($this->vet->id, $start, $start->modify('+90 minutes'));
        $appointment = $this->appointments->create($this->petId, $this->vet->id, $start->modify('+30 minutes'), null);
        $this->appointments->updateStatus($appointment->id, AppointmentStatus::Cancelled);

        //act
        $slots = $this->service->openSlots($this->vet->id);

        //assert
        self::assertCount(3, $slots);
    }

    public function testOpenSlotsExcludesPastPortionOfWindowButKeepsGridAlignment(): void
    {
        //arrange
        $now = new DateTimeImmutable('now');
        $windowStart = $now->modify('-45 minutes');
        $windowEnd = $now->modify('+2 hours');
        $this->availability->create($this->vet->id, $windowStart, $windowEnd);

        //act
        $slots = $this->service->openSlots($this->vet->id);

        //assert
        self::assertNotEmpty($slots);
        foreach ($slots as $slot) {
            self::assertGreaterThanOrEqual($now->getTimestamp(), $slot->getTimestamp());
            self::assertSame(0, ($slot->getTimestamp() - $windowStart->getTimestamp()) % 1800);
        }
    }

    public function testOpenSlotsCapsAtMaximumSlotCount(): void
    {
        //arrange
        $start = $this->instant('+1 day');
        $this->availability->create($this->vet->id, $start, $start->modify('+60 days'));

        //act
        $slots = $this->service->openSlots($this->vet->id);

        //assert
        self::assertCount(50, $slots);
        self::assertEquals($start, $slots[0]);
        self::assertEquals($start->modify('+' . (49 * 30) . ' minutes'), $slots[49]);
    }

    public function testOpenSlotsIsScopedToRequestedVetOnly(): void
    {
        //arrange
        $otherVetEmail = 'avail-other-vet-' . bin2hex(random_bytes(6)) . '@example.test';
        $otherVet = $this->registerVet($otherVetEmail);

        $start = $this->instant('+2 weeks');
        $this->availability->create($this->vet->id, $start, $start->modify('+30 minutes'));
        $this->availability->create($otherVet->id, $start->modify('+5 hours'), $start->modify('+5 hours 30 minutes'));

        try {
            //act
            $slots = $this->service->openSlots($this->vet->id);

            //assert
            self::assertCount(1, $slots);
            self::assertEquals($start, $slots[0]);
        } finally {
            Database::connection()
                ->prepare('DELETE FROM users WHERE email = :email')
                ->execute(['email' => $otherVetEmail]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\AppointmentStatus;
use App\Domain\Owner;
use App\Domain\Vet;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\Exception\AppointmentSlotUnavailableException;
use App\Repository\PetRepository;
use App\Service\AppointmentTransitionService;
use App\Service\Exception\AppointmentNotCancellableException;
use App\Tests\Support\CreatesTestUsers;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AppointmentTransitionServiceTest extends TestCase
{
    use CreatesTestUsers;

    private AppointmentTransitionService $service;
    private AppointmentRepository $appointments;
    private string $ownerEmail;
    private string $vetEmail;
    private string $otherVetEmail;
    private Owner $owner;
    private Vet $vet;
    private Vet $otherVet;
    private int $petId;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "transition-owner-{$suffix}@example.test";
        $this->vetEmail = "transition-vet-{$suffix}@example.test";
        $this->otherVetEmail = "transition-other-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->otherVet = $this->registerVet($this->otherVetEmail);
        $this->petId = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null)->id;

        $this->appointments = new AppointmentRepository();
        $this->service = new AppointmentTransitionService($this->appointments, new PetRepository());
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet, :other)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail, 'other' => $this->otherVetEmail]);
    }

    public function testTransitionAppliesAllowedTransition(): void
    {
        //arrange
        $appointment = $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), null);

        //act
        $result = $this->service->transition($appointment->id, $this->vet->id, [AppointmentStatus::Requested], AppointmentStatus::Confirmed);

        //assert
        self::assertTrue($result);
        self::assertSame(AppointmentStatus::Confirmed, $this->appointments->findById($appointment->id)->status);
    }

    public function testTransitionIsNoOpForAnotherVetsAppointment(): void
    {
        //arrange
        $appointment = $this->appointments->create($this->petId, $this->otherVet->id, new DateTimeImmutable('+1 week'), null);

        //act
        $result = $this->service->transition($appointment->id, $this->vet->id, [AppointmentStatus::Requested], AppointmentStatus::Confirmed);

        //assert
        self::assertFalse($result);
        self::assertSame(AppointmentStatus::Requested, $this->appointments->findById($appointment->id)->status);
    }

    public function testTransitionIsNoOpWhenCurrentStatusNotAllowed(): void
    {
        //arrange
        $appointment = $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), null);
        $this->appointments->updateStatus($appointment->id, AppointmentStatus::Completed);

        //act
        $result = $this->service->transition($appointment->id, $this->vet->id, [AppointmentStatus::Requested, AppointmentStatus::Confirmed], AppointmentStatus::Cancelled);

        //assert
        self::assertFalse($result);
        self::assertSame(AppointmentStatus::Completed, $this->appointments->findById($appointment->id)->status);
    }

    public function testTransitionToCancelledIsNoOpPastCutoff(): void
    {
        //arrange — within the 2-hour cancellation cutoff
        $appointment = $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 hour'), null);

        //act
        $result = $this->service->transition($appointment->id, $this->vet->id, [AppointmentStatus::Requested], AppointmentStatus::Cancelled);

        //assert
        self::assertFalse($result);
        self::assertSame(AppointmentStatus::Requested, $this->appointments->findById($appointment->id)->status);
    }

    public function testCancelAsOwnerCancelsOwnedUpcomingAppointment(): void
    {
        //arrange
        $appointment = $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), null);

        //act
        $result = $this->service->cancelAsOwner($appointment->id, $this->owner->id);

        //assert
        self::assertTrue($result);
        self::assertSame(AppointmentStatus::Cancelled, $this->appointments->findById($appointment->id)->status);
    }

    public function testCancelAsOwnerFailsForAnotherOwnersAppointment(): void
    {
        //arrange
        $otherOwnerEmail = 'transition-other-owner-' . bin2hex(random_bytes(6)) . '@example.test';
        $otherOwner = $this->registerOwner($otherOwnerEmail);
        $appointment = $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), null);

        try {
            //act
            $result = $this->service->cancelAsOwner($appointment->id, $otherOwner->id);

            //assert
            self::assertFalse($result);
            self::assertSame(AppointmentStatus::Requested, $this->appointments->findById($appointment->id)->status);
        } finally {
            Database::connection()
                ->prepare('DELETE FROM users WHERE email = :email')
                ->execute(['email' => $otherOwnerEmail]);
        }
    }

    public function testCancelAsOwnerFailsPastCutoff(): void
    {
        //arrange
        $appointment = $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 hour'), null);

        //act
        $result = $this->service->cancelAsOwner($appointment->id, $this->owner->id);

        //assert
        self::assertFalse($result);
        self::assertSame(AppointmentStatus::Requested, $this->appointments->findById($appointment->id)->status);
    }

    public function testRescheduleAsOwnerCancelsOldAndCreatesNew(): void
    {
        //arrange
        $oldSlot = new DateTimeImmutable('+1 week');
        $appointment = $this->appointments->create($this->petId, $this->vet->id, $oldSlot, 'Checkup');
        // scheduled_at round-trips through the DB with second precision only
        // (see AppointmentAvailabilityServiceTest::instant()), so build the
        // fixture the same way to keep assertEquals() exact.
        $newSlot = new DateTimeImmutable((new DateTimeImmutable('+2 weeks'))->format('Y-m-d H:i:s'));

        //act
        $rescheduled = $this->service->rescheduleAsOwner($appointment->id, $this->owner->id, $newSlot, 45, 'Follow-up');

        //assert
        self::assertSame(AppointmentStatus::Cancelled, $this->appointments->findById($appointment->id)->status);
        self::assertNotSame($appointment->id, $rescheduled->id);
        self::assertEquals($newSlot, $rescheduled->scheduledAt);
        self::assertSame(45, $rescheduled->durationMinutes);
        self::assertSame('Follow-up', $rescheduled->reason);
        self::assertSame(AppointmentStatus::Requested, $rescheduled->status);
    }

    public function testRescheduleAsOwnerRollsBackWhenNewSlotOverlapsExistingAppointment(): void
    {
        //arrange
        $oldSlot = new DateTimeImmutable('+1 week');
        $appointment = $this->appointments->create($this->petId, $this->vet->id, $oldSlot, null);
        $conflictingSlot = new DateTimeImmutable('+2 weeks');
        $this->appointments->create($this->petId, $this->vet->id, $conflictingSlot, null, 60);

        //assert
        $this->expectException(AppointmentSlotUnavailableException::class);

        try {
            //act
            $this->service->rescheduleAsOwner($appointment->id, $this->owner->id, $conflictingSlot, 30, null);
        } finally {
            // the whole transaction must roll back — the original appointment
            // stays exactly as it was, not left cancelled with no replacement
            self::assertSame(AppointmentStatus::Requested, $this->appointments->findById($appointment->id)->status);
        }
    }

    public function testRescheduleAsOwnerFailsPastCutoff(): void
    {
        //arrange
        $appointment = $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 hour'), null);

        //assert
        $this->expectException(AppointmentNotCancellableException::class);

        //act
        $this->service->rescheduleAsOwner($appointment->id, $this->owner->id, new DateTimeImmutable('+2 weeks'), 30, null);
    }
}

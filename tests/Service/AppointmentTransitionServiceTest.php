<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\AppointmentStatus;
use App\Domain\Owner;
use App\Domain\Vet;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Service\AppointmentTransitionService;
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
        $this->service = new AppointmentTransitionService($this->appointments);
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
}

<?php

declare(strict_types=1);

namespace App\Tests\Vet;

use App\Domain\AppointmentStatus;
use App\Domain\DayOfWeek;
use App\Domain\Owner;
use App\Domain\Role;
use App\Domain\Vet;
use App\Infrastructure\Database;
use App\Repository\ActivityLogRepository;
use App\Repository\AppointmentRepository;
use App\Repository\AvailabilityRepository;
use App\Repository\OwnerRepository;
use App\Repository\PetRepository;
use App\Repository\UserRepository;
use App\Repository\VetRepository;
use App\Repository\VisitRepository;
use App\Service\AuthService;
use App\Service\VisitService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class VetPortalTest extends TestCase
{
    private string $ownerEmail;
    private string $vetEmail;
    private Owner $owner;
    private Vet $vet;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "owner-{$suffix}@example.test";
        $this->vetEmail = "vet-{$suffix}@example.test";

        $auth = new AuthService(new UserRepository(), new OwnerRepository(), new VetRepository(), new ActivityLogRepository());

        $ownerUser = $auth->register($this->ownerEmail, 'correct-horse', Role::Owner, [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'address' => '1 Main St',
        ]);
        $vetUser = $auth->register($this->vetEmail, 'correct-horse', Role::Vet, [
            'first_name' => 'Alex',
            'last_name' => 'Vetter',
            'specialty' => 'Surgery',
        ]);

        $this->owner = (new OwnerRepository())->findByUserId($ownerUser->id);
        $this->vet = (new VetRepository())->findByUserId($vetUser->id);
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
    }

    public function testVetCanCreateAvailabilitySlot(): void
    {
        //arrange
        $availabilityRepository = new AvailabilityRepository();

        //act
        $availabilityRepository->create($this->vet->id, DayOfWeek::Monday, new DateTimeImmutable('09:00'), new DateTimeImmutable('17:00'));

        //assert
        self::assertCount(1, $availabilityRepository->findAllByVetId($this->vet->id));
    }

    public function testVetCanDeleteAvailabilitySlot(): void
    {
        //arrange
        $availabilityRepository = new AvailabilityRepository();
        $slot = $availabilityRepository->create($this->vet->id, DayOfWeek::Monday, new DateTimeImmutable('09:00'), new DateTimeImmutable('17:00'));

        //act
        $availabilityRepository->delete($slot->id, $this->vet->id);

        //assert
        self::assertCount(0, $availabilityRepository->findAllByVetId($this->vet->id));
    }

    public function testAppointmentIsCreatedAsRequestedAndListedForVet(): void
    {
        //arrange
        $pet = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null);
        $appointmentRepository = new AppointmentRepository();

        //act
        $appointment = $appointmentRepository->create($pet->id, $this->vet->id, new DateTimeImmutable('+1 week'), 'Checkup');

        //assert
        self::assertSame(AppointmentStatus::Requested, $appointment->status);
        $found = $appointmentRepository->findAllByVetId($this->vet->id);
        self::assertCount(1, $found);
        self::assertSame($appointment->id, $found[0]->id);
    }

    public function testVetCanConfirmAppointment(): void
    {
        //arrange
        $pet = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null);
        $appointmentRepository = new AppointmentRepository();
        $appointment = $appointmentRepository->create($pet->id, $this->vet->id, new DateTimeImmutable('+1 week'), 'Checkup');

        //act
        $appointmentRepository->updateStatus($appointment->id, AppointmentStatus::Confirmed);

        //assert
        $confirmed = $appointmentRepository->findById($appointment->id);
        self::assertSame(AppointmentStatus::Confirmed, $confirmed->status);
    }

    public function testVetCanRecordVisitWhichCompletesTheAppointment(): void
    {
        //arrange
        $pet = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null);
        $appointmentRepository = new AppointmentRepository();
        $appointment = $appointmentRepository->create($pet->id, $this->vet->id, new DateTimeImmutable('+1 week'), 'Checkup');
        $appointmentRepository->updateStatus($appointment->id, AppointmentStatus::Confirmed);
        $confirmed = $appointmentRepository->findById($appointment->id);
        $visitService = new VisitService($appointmentRepository, new VisitRepository());

        //act
        $visit = $visitService->recordVisit($confirmed, 'Healthy', 'Rabies booster', 'No concerns.');

        //assert
        self::assertSame('Healthy', $visit->diagnosis);
        $completed = $appointmentRepository->findById($appointment->id);
        self::assertSame(AppointmentStatus::Completed, $completed->status);
        $visits = (new VisitRepository())->findAllByPetIds([$pet->id]);
        self::assertCount(1, $visits);
        self::assertSame($visit->id, $visits[0]->id);
    }
}

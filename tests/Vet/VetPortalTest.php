<?php

declare(strict_types=1);

namespace App\Tests\Vet;

use App\Domain\AppointmentStatus;
use App\Domain\Role;
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

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "owner-{$suffix}@example.test";
        $this->vetEmail = "vet-{$suffix}@example.test";
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
    }

    public function testVetCanManageAvailabilityAndAppointmentLifecycle(): void
    {
        //arrange
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

        $owner = (new OwnerRepository())->findByUserId($ownerUser->id);
        $vet = (new VetRepository())->findByUserId($vetUser->id);
        self::assertNotNull($owner);
        self::assertNotNull($vet);

        //act
        $availabilityRepository = new AvailabilityRepository();
        $slot = $availabilityRepository->create($vet->id, new DateTimeImmutable('+1 day 09:00'), new DateTimeImmutable('+1 day 17:00'));

        //assert
        self::assertCount(1, $availabilityRepository->findAllByVetId($vet->id));

        //act
        $availabilityRepository->delete($slot->id, $vet->id);

        //assert
        self::assertCount(0, $availabilityRepository->findAllByVetId($vet->id));

        //arrange
        $pet = (new PetRepository())->create($owner->id, 'Rex', 'Dog', null, null);
        $appointmentRepository = new AppointmentRepository();

        //act
        $appointment = $appointmentRepository->create($pet->id, $vet->id, new DateTimeImmutable('+1 week'), 'Checkup');

        //assert
        self::assertSame(AppointmentStatus::Requested, $appointment->status);

        $found = $appointmentRepository->findAllByVetId($vet->id);
        self::assertCount(1, $found);
        self::assertSame($appointment->id, $found[0]->id);

        //act
        $appointmentRepository->updateStatus($appointment->id, AppointmentStatus::Confirmed);
        $confirmed = $appointmentRepository->findById($appointment->id);

        //assert
        self::assertSame(AppointmentStatus::Confirmed, $confirmed->status);

        //act
        $visitService = new VisitService($appointmentRepository, new VisitRepository());
        $visit = $visitService->recordVisit($confirmed, 'Healthy', 'No concerns.');

        //assert
        self::assertSame('Healthy', $visit->diagnosis);

        $completed = $appointmentRepository->findById($appointment->id);
        self::assertSame(AppointmentStatus::Completed, $completed->status);

        $visits = (new VisitRepository())->findAllByPetIds([$pet->id]);
        self::assertCount(1, $visits);
        self::assertSame($visit->id, $visits[0]->id);
    }
}

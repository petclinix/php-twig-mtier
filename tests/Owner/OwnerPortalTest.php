<?php

declare(strict_types=1);

namespace App\Tests\Owner;

use App\Domain\AppointmentStatus;
use App\Domain\Owner;
use App\Domain\Role;
use App\Domain\Vet;
use App\Infrastructure\Database;
use App\Repository\ActivityLogRepository;
use App\Repository\AppointmentRepository;
use App\Repository\OwnerRepository;
use App\Repository\PetRepository;
use App\Repository\UserRepository;
use App\Repository\VetRepository;
use App\Repository\VisitRepository;
use App\Service\AuthService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class OwnerPortalTest extends TestCase
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

    public function testOwnerCanRegisterAPet(): void
    {
        //act
        (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', 'Labrador', new DateTimeImmutable('2020-01-01'));

        //assert
        $pets = (new PetRepository())->findAllByOwnerId($this->owner->id);
        self::assertCount(1, $pets);
        self::assertSame('Rex', $pets[0]->name);
    }

    public function testOwnerCanBookAppointment(): void
    {
        //arrange
        $pet = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', 'Labrador', new DateTimeImmutable('2020-01-01'));

        //act
        $appointment = (new AppointmentRepository())->create(
            $pet->id,
            $this->vet->id,
            new DateTimeImmutable('+1 week'),
            'Annual checkup',
        );

        //assert
        self::assertSame(AppointmentStatus::Requested, $appointment->status);
        $appointments = (new AppointmentRepository())->findAllByPetIds([$pet->id]);
        self::assertCount(1, $appointments);
        self::assertSame($appointment->id, $appointments[0]->id);
    }

    public function testOwnerCanSeeVisitHistory(): void
    {
        //arrange
        $pet = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', 'Labrador', new DateTimeImmutable('2020-01-01'));
        $appointment = (new AppointmentRepository())->create(
            $pet->id,
            $this->vet->id,
            new DateTimeImmutable('+1 week'),
            'Annual checkup',
        );
        // Recording a visit is a Vet-phase feature (Phase 4); insert directly to verify the read path.
        Database::connection()
            ->prepare('INSERT INTO visits (appointment_id, diagnosis, notes) VALUES (:appointment_id, :diagnosis, :notes)')
            ->execute(['appointment_id' => $appointment->id, 'diagnosis' => 'Healthy', 'notes' => 'No concerns.']);

        //act
        $visits = (new VisitRepository())->findAllByPetIds([$pet->id]);

        //assert
        self::assertCount(1, $visits);
        self::assertSame('Healthy', $visits[0]->diagnosis);
    }
}

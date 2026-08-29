<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Owner;
use App\Domain\Vet;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Repository\VetRepository;
use App\Repository\VisitRepository;
use App\Service\OwnerVisitBoardService;
use App\Tests\Support\CreatesTestUsers;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class OwnerVisitBoardServiceTest extends TestCase
{
    use CreatesTestUsers;

    private OwnerVisitBoardService $service;
    private AppointmentRepository $appointments;
    private VisitRepository $visits;
    private string $ownerEmail;
    private string $vetEmail;
    private Owner $owner;
    private Vet $vet;
    private int $petId;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "visitboard-owner-{$suffix}@example.test";
        $this->vetEmail = "visitboard-vet-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->petId = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null)->id;

        $this->appointments = new AppointmentRepository();
        $this->visits = new VisitRepository();
        $this->service = new OwnerVisitBoardService(
            new PetRepository(),
            new VetRepository(),
            $this->appointments,
            $this->visits,
        );
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
    }

    public function testForOwnerReturnsEmptyBoardWithNoVisits(): void
    {
        //act
        $board = $this->service->forOwner($this->owner->id);

        //assert
        self::assertSame([], $board['visits']);
        self::assertSame([], $board['appointmentsById']);
        self::assertArrayHasKey($this->petId, $board['petsById']);
        self::assertArrayHasKey($this->vet->id, $board['vetsById']);
    }

    public function testForOwnerIndexesVisitAppointment(): void
    {
        //arrange
        $appointment = $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), null);
        $this->visits->create($appointment->id, 'Healthy', null);

        //act
        $board = $this->service->forOwner($this->owner->id);

        //assert
        self::assertCount(1, $board['visits']);
        self::assertArrayHasKey($appointment->id, $board['appointmentsById']);
        self::assertSame($appointment->id, $board['appointmentsById'][$appointment->id]->id);
        self::assertSame('Rex', $board['petsById'][$this->petId]->name);
    }
}

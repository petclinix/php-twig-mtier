<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Owner;
use App\Domain\Vet;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\OwnerRepository;
use App\Repository\PetRepository;
use App\Service\VetAppointmentBoardService;
use App\Tests\Support\CreatesTestUsers;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class VetAppointmentBoardServiceTest extends TestCase
{
    use CreatesTestUsers;

    private VetAppointmentBoardService $service;
    private AppointmentRepository $appointments;
    private string $ownerEmail;
    private string $vetEmail;
    private Owner $owner;
    private Vet $vet;
    private int $petId;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "board-owner-{$suffix}@example.test";
        $this->vetEmail = "board-vet-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->petId = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null)->id;

        $this->appointments = new AppointmentRepository();
        $this->service = new VetAppointmentBoardService(
            $this->appointments,
            new PetRepository(),
            new OwnerRepository(),
        );
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
    }

    public function testForVetReturnsEmptyBoardWithNoAppointments(): void
    {
        //act
        $board = $this->service->forVet($this->vet->id);

        //assert
        self::assertSame([], $board['appointments']);
        self::assertSame([], $board['petsById']);
        self::assertSame([], $board['ownersById']);
    }

    public function testForVetIndexesPetAndOwnerByAppointment(): void
    {
        //arrange
        $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), null);

        //act
        $board = $this->service->forVet($this->vet->id);

        //assert
        self::assertCount(1, $board['appointments']);
        self::assertArrayHasKey($this->petId, $board['petsById']);
        self::assertSame('Rex', $board['petsById'][$this->petId]->name);
        self::assertArrayHasKey($this->owner->id, $board['ownersById']);
        self::assertSame($this->owner->id, $board['ownersById'][$this->owner->id]->id);
    }

    public function testForVetDedupesPetAndOwnerAcrossMultipleAppointments(): void
    {
        //arrange
        $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), null);
        $this->appointments->create($this->petId, $this->vet->id, new DateTimeImmutable('+2 weeks'), null);

        //act
        $board = $this->service->forVet($this->vet->id);

        //assert
        self::assertCount(2, $board['appointments']);
        self::assertCount(1, $board['petsById']);
        self::assertCount(1, $board['ownersById']);
    }
}

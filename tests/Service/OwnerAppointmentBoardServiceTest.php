<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Owner;
use App\Domain\Vet;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\AvailabilityRepository;
use App\Repository\PetRepository;
use App\Repository\VetRepository;
use App\Service\AppointmentAvailabilityService;
use App\Service\OwnerAppointmentBoardService;
use App\Tests\Support\CreatesTestUsers;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class OwnerAppointmentBoardServiceTest extends TestCase
{
    use CreatesTestUsers;

    private OwnerAppointmentBoardService $service;
    private AvailabilityRepository $availability;
    private string $ownerEmail;
    private string $vetEmail;
    private Owner $owner;
    private Vet $vet;
    private int $petId;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "apptboard-owner-{$suffix}@example.test";
        $this->vetEmail = "apptboard-vet-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->petId = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null)->id;

        $this->availability = new AvailabilityRepository();
        $this->service = new OwnerAppointmentBoardService(
            new PetRepository(),
            new VetRepository(),
            new AppointmentRepository(),
            new AppointmentAvailabilityService($this->availability, new AppointmentRepository()),
        );
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
    }

    private function instant(string $modifier): DateTimeImmutable
    {
        return new DateTimeImmutable((new DateTimeImmutable($modifier))->format('Y-m-d H:i:s'));
    }

    public function testForOwnerReturnsNoSlotOptionsWithoutSelectedVet(): void
    {
        //act
        $board = $this->service->forOwner($this->owner->id, 0, 30);

        //assert
        self::assertSame([], $board['slotOptions']);
        self::assertArrayHasKey($this->petId, $board['petsById']);
        self::assertArrayHasKey($this->vet->id, $board['vetsById']);
    }

    public function testForOwnerReturnsSlotOptionsForSelectedVet(): void
    {
        //arrange
        $start = $this->instant('+2 weeks');
        $this->availability->create($this->vet->id, $start, $start->modify('+30 minutes'));

        //act
        $board = $this->service->forOwner($this->owner->id, $this->vet->id, 30);

        //assert
        self::assertCount(1, $board['slotOptions']);
        self::assertSame($start->format('Y-m-d\TH:i'), $board['slotOptions'][0]['value']);
    }
}

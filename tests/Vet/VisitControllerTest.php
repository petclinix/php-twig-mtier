<?php

declare(strict_types=1);

namespace App\Tests\Vet;

use App\Domain\AppointmentStatus;
use App\Domain\Owner;
use App\Domain\Vet;
use App\Http\Controller\Vet\VisitController;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Repository\VisitRepository;
use App\Tests\Support\CreatesTestUsers;
use App\Tests\Support\HeaderSpy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class VisitControllerTest extends TestCase
{
    use CreatesTestUsers;

    private VisitController $controller;
    private string $ownerEmail;
    private string $vetEmail;
    private string $otherVetEmail;
    private Owner $owner;
    private Vet $vet;
    private Vet $otherVet;
    private int $petId;

    protected function setUp(): void
    {
        HeaderSpy::reset();
        $_POST = [];
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "vetvisit-owner-{$suffix}@example.test";
        $this->vetEmail = "vetvisit-vet-{$suffix}@example.test";
        $this->otherVetEmail = "vetvisit-other-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->otherVet = $this->registerVet($this->otherVetEmail);
        $this->petId = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null)->id;
        $this->loginAs($this->vet->userId, 'vet');
        $this->controller = new VisitController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet, :other)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail, 'other' => $this->otherVetEmail]);
        $_POST = [];
    }

    private function createAppointment(AppointmentStatus $status, ?Vet $vet = null): int
    {
        $appointment = (new AppointmentRepository())->create($this->petId, ($vet ?? $this->vet)->id, new DateTimeImmutable('-1 day'), null);
        (new AppointmentRepository())->updateStatus($appointment->id, $status);

        return $appointment->id;
    }

    public function testCreateRendersFormForOwnConfirmedAppointment(): void
    {
        //arrange
        $appointmentId = $this->createAppointment(AppointmentStatus::Confirmed);

        //act
        $output = $this->controller->create(['id' => (string) $appointmentId]);

        //assert
        self::assertStringContainsString('Record Visit', $output);
        self::assertStringContainsString('Rex', $output);
    }

    public function testCreateRedirectsWhenAppointmentNotFound(): void
    {
        //act
        $output = $this->controller->create(['id' => '999999999']);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/appointments', HeaderSpy::location());
    }

    public function testCreateRedirectsWhenAppointmentBelongsToAnotherVet(): void
    {
        //arrange
        $appointmentId = $this->createAppointment(AppointmentStatus::Confirmed, $this->otherVet);

        //act
        $output = $this->controller->create(['id' => (string) $appointmentId]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/appointments', HeaderSpy::location());
    }

    public function testCreateRedirectsWhenAppointmentNotConfirmed(): void
    {
        //arrange
        $appointmentId = $this->createAppointment(AppointmentStatus::Requested);

        //act
        $output = $this->controller->create(['id' => (string) $appointmentId]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/appointments', HeaderSpy::location());
    }

    public function testStoreWithValidDataRecordsVisitAndCompletesAppointment(): void
    {
        //arrange
        $appointmentId = $this->createAppointment(AppointmentStatus::Confirmed);
        $_POST = ['diagnosis' => 'Healthy', 'vaccination' => 'Rabies booster', 'notes' => 'No concerns.'];

        //act
        $output = $this->controller->store(['id' => (string) $appointmentId]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/appointments', HeaderSpy::location());
        $updated = (new AppointmentRepository())->findById($appointmentId);
        self::assertSame(AppointmentStatus::Completed, $updated->status);
        $visits = (new VisitRepository())->findAllByPetIds([$this->petId]);
        self::assertCount(1, $visits);
        self::assertSame('Healthy', $visits[0]->diagnosis);
        self::assertSame('Rabies booster', $visits[0]->vaccination);
    }

    public function testStoreRedirectsWithoutRecordingVisitWhenAppointmentNotConfirmed(): void
    {
        //arrange
        $appointmentId = $this->createAppointment(AppointmentStatus::Requested);
        $_POST = ['diagnosis' => 'Healthy', 'notes' => ''];

        //act
        $output = $this->controller->store(['id' => (string) $appointmentId]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/appointments', HeaderSpy::location());
        self::assertCount(0, (new VisitRepository())->findAllByPetIds([$this->petId]));
    }
}

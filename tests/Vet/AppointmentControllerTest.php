<?php

declare(strict_types=1);

namespace App\Tests\Vet;

use App\Domain\AppointmentStatus;
use App\Domain\Owner;
use App\Domain\Vet;
use App\Http\Controller\Vet\AppointmentController;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Tests\Support\CreatesTestUsers;
use App\Tests\Support\HeaderSpy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AppointmentControllerTest extends TestCase
{
    use CreatesTestUsers;

    private AppointmentController $controller;
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
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "vetappt-owner-{$suffix}@example.test";
        $this->vetEmail = "vetappt-vet-{$suffix}@example.test";
        $this->otherVetEmail = "vetappt-other-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->otherVet = $this->registerVet($this->otherVetEmail);
        $this->petId = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null)->id;
        $this->loginAs($this->vet->userId, 'vet');
        $this->controller = new AppointmentController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet, :other)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail, 'other' => $this->otherVetEmail]);
    }

    public function testIndexShowsEmptyStateWithNoAppointments(): void
    {
        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringContainsString('My Appointments', $output);
        self::assertStringContainsString('No appointments assigned yet.', $output);
    }

    public function testIndexListsVetsAppointments(): void
    {
        //arrange
        (new AppointmentRepository())->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), 'Checkup');

        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringContainsString('Rex', $output);
    }

    public function testConfirmTransitionsRequestedToConfirmed(): void
    {
        //arrange
        $appointment = (new AppointmentRepository())->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), null);

        //act
        $output = $this->controller->confirm(['id' => (string) $appointment->id]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/appointments', HeaderSpy::location());
        $updated = (new AppointmentRepository())->findById($appointment->id);
        self::assertSame(AppointmentStatus::Confirmed, $updated->status);
    }

    public function testConfirmIsNoOpForAnotherVetsAppointment(): void
    {
        //arrange
        $appointment = (new AppointmentRepository())->create($this->petId, $this->otherVet->id, new DateTimeImmutable('+1 week'), null);

        //act
        $output = $this->controller->confirm(['id' => (string) $appointment->id]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/appointments', HeaderSpy::location());
        $updated = (new AppointmentRepository())->findById($appointment->id);
        self::assertSame(AppointmentStatus::Requested, $updated->status);
    }

    public function testCancelTransitionsConfirmedToCancelled(): void
    {
        //arrange
        $appointment = (new AppointmentRepository())->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), null);
        (new AppointmentRepository())->updateStatus($appointment->id, AppointmentStatus::Confirmed);

        //act
        $output = $this->controller->cancel(['id' => (string) $appointment->id]);

        //assert
        self::assertSame('', $output);
        $updated = (new AppointmentRepository())->findById($appointment->id);
        self::assertSame(AppointmentStatus::Cancelled, $updated->status);
    }

    public function testCancelIsNoOpForCompletedAppointment(): void
    {
        //arrange
        $appointment = (new AppointmentRepository())->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), null);
        (new AppointmentRepository())->updateStatus($appointment->id, AppointmentStatus::Completed);

        //act
        $output = $this->controller->cancel(['id' => (string) $appointment->id]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/appointments', HeaderSpy::location());
        $updated = (new AppointmentRepository())->findById($appointment->id);
        self::assertSame(AppointmentStatus::Completed, $updated->status);
    }

    public function testCancelIsNoOpWithinCancellationCutoff(): void
    {
        //arrange — within the 2-hour cancellation cutoff
        $appointment = (new AppointmentRepository())->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 hour'), null);

        //act
        $output = $this->controller->cancel(['id' => (string) $appointment->id]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/appointments', HeaderSpy::location());
        $updated = (new AppointmentRepository())->findById($appointment->id);
        self::assertSame(AppointmentStatus::Requested, $updated->status);
    }
}

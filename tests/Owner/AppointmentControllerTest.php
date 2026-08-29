<?php

declare(strict_types=1);

namespace App\Tests\Owner;

use App\Domain\AppointmentStatus;
use App\Domain\Owner;
use App\Domain\Vet;
use App\Http\Controller\Owner\AppointmentController;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\AvailabilityRepository;
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
    private Owner $owner;
    private Vet $vet;
    private int $petId;
    private DateTimeImmutable $appointmentSlot;

    protected function setUp(): void
    {
        HeaderSpy::reset();
        $_POST = [];
        $_GET = [];
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "appt-owner-{$suffix}@example.test";
        $this->vetEmail = "appt-vet-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->petId = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null)->id;
        $this->appointmentSlot = new DateTimeImmutable('+1 week');
        (new AvailabilityRepository())->create($this->vet->id, $this->appointmentSlot, $this->appointmentSlot->modify('+2 hours'));
        $this->loginAs($this->owner->userId, 'owner');
        $this->controller = new AppointmentController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
        $_POST = [];
        $_GET = [];
    }

    public function testIndexListsOwnersAppointments(): void
    {
        //arrange
        (new AppointmentRepository())->create($this->petId, $this->vet->id, $this->appointmentSlot, 'Checkup');

        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringContainsString('My Appointments', $output);
        self::assertStringContainsString('Rex', $output);
    }

    public function testIndexWithVetIdShowsAvailableSlots(): void
    {
        //arrange
        $_GET = ['vet_id' => (string) $this->vet->id];

        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringContainsString('name="scheduled_at"', $output);
        self::assertStringContainsString($this->appointmentSlot->format('Y-m-d\TH:i'), $output);
    }

    public function testIndexWithoutVetIdDoesNotShowSlotPicker(): void
    {
        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringNotContainsString('name="scheduled_at"', $output);
    }

    public function testIndexWithUnknownVetIdShowsNoSlots(): void
    {
        //arrange
        $_GET = ['vet_id' => '999999999'];

        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringNotContainsString('name="scheduled_at"', $output);
    }

    public function testStoreWithValidDataCreatesAppointmentAndRedirects(): void
    {
        //arrange
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => $this->appointmentSlot->format('Y-m-d\TH:i'),
            'reason' => 'Checkup',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/owner/appointments', HeaderSpy::location());
        $appointments = (new AppointmentRepository())->findAllByPetIds([$this->petId]);
        self::assertCount(1, $appointments);
    }

    public function testStoreWithPetNotOwnedShowsValidationError(): void
    {
        //arrange
        $_POST = [
            'pet_id' => '999999999',
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => $this->appointmentSlot->format('Y-m-d\TH:i'),
            'reason' => '',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('Choose one of your pets.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithInvalidVetShowsValidationError(): void
    {
        //arrange
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => '999999999',
            'scheduled_at' => $this->appointmentSlot->format('Y-m-d\TH:i'),
            'reason' => '',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('Choose a vet.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithEmptyDateTimeShowsValidationError(): void
    {
        //arrange
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => '',
            'reason' => '',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('Choose a date and time.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithPastDateTimeShowsValidationError(): void
    {
        //arrange
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => (new DateTimeImmutable('-1 week'))->format('Y-m-d\TH:i'),
            'reason' => '',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('Choose a time in the future.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithTimeOutsideAvailabilityShowsValidationError(): void
    {
        //arrange
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => (new DateTimeImmutable('+3 weeks'))->format('Y-m-d\TH:i'),
            'reason' => '',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('That time is no longer available. Please choose another.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithTimeConflictingWithExistingAppointmentShowsValidationError(): void
    {
        //arrange
        (new AppointmentRepository())->create($this->petId, $this->vet->id, $this->appointmentSlot, 'Checkup');
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => $this->appointmentSlot->format('Y-m-d\TH:i'),
            'reason' => '',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('That time is no longer available. Please choose another.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreIgnoresCancelledAppointmentWhenCheckingConflicts(): void
    {
        //arrange
        $repository = new AppointmentRepository();
        $conflicting = $repository->create($this->petId, $this->vet->id, $this->appointmentSlot, 'Checkup');
        $repository->updateStatus($conflicting->id, AppointmentStatus::Cancelled);
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => $this->appointmentSlot->format('Y-m-d\TH:i'),
            'reason' => '',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/owner/appointments', HeaderSpy::location());
    }

    public function testStoreWithNonDefaultDurationCreatesAppointmentWithThatDuration(): void
    {
        //arrange
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => $this->appointmentSlot->format('Y-m-d\TH:i'),
            'duration_minutes' => '60',
            'reason' => 'Surgery',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/owner/appointments', HeaderSpy::location());
        $appointments = (new AppointmentRepository())->findAllByPetIds([$this->petId]);
        self::assertCount(1, $appointments);
        self::assertSame(60, $appointments[0]->durationMinutes);
    }

    public function testStoreWithInvalidDurationShowsValidationError(): void
    {
        //arrange
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => $this->appointmentSlot->format('Y-m-d\TH:i'),
            'duration_minutes' => '99',
            'reason' => '',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('Choose a valid duration.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreRejectsBookingOverlappingLongerExistingAppointmentAtDifferentStartTime(): void
    {
        //arrange — a 60-minute booking starting 30 minutes into the availability window
        (new AppointmentRepository())->create($this->petId, $this->vet->id, $this->appointmentSlot->modify('+30 minutes'), 'Surgery', 60);
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            // starts where a fixed-30-minute assumption would have thought the
            // existing booking already ended, but the real 60-minute booking is
            // still running until +90 minutes
            'scheduled_at' => $this->appointmentSlot->modify('+60 minutes')->format('Y-m-d\TH:i'),
            'duration_minutes' => '30',
            'reason' => '',
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('That time is no longer available. Please choose another.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }
}

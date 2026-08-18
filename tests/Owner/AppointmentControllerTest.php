<?php

declare(strict_types=1);

namespace App\Tests\Owner;

use App\Domain\Owner;
use App\Domain\Vet;
use App\Http\Controller\Owner\AppointmentController;
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
    private Owner $owner;
    private Vet $vet;
    private int $petId;

    protected function setUp(): void
    {
        HeaderSpy::reset();
        $_POST = [];
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "appt-owner-{$suffix}@example.test";
        $this->vetEmail = "appt-vet-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->petId = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null)->id;
        $this->loginAs($this->owner->userId, 'owner');
        $this->controller = new AppointmentController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
        $_POST = [];
    }

    public function testIndexListsOwnersAppointments(): void
    {
        (new AppointmentRepository())->create($this->petId, $this->vet->id, new DateTimeImmutable('+1 week'), 'Checkup');

        $output = $this->controller->index([]);

        self::assertStringContainsString('My Appointments', $output);
        self::assertStringContainsString('Rex', $output);
    }

    public function testStoreWithValidDataCreatesAppointmentAndRedirects(): void
    {
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => (new DateTimeImmutable('+1 week'))->format('Y-m-d\TH:i'),
            'reason' => 'Checkup',
        ];

        $output = $this->controller->store([]);

        self::assertSame('', $output);
        self::assertSame('/owner/appointments', HeaderSpy::location());
        $appointments = (new AppointmentRepository())->findAllByPetIds([$this->petId]);
        self::assertCount(1, $appointments);
    }

    public function testStoreWithPetNotOwnedShowsValidationError(): void
    {
        $_POST = [
            'pet_id' => '999999999',
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => (new DateTimeImmutable('+1 week'))->format('Y-m-d\TH:i'),
            'reason' => '',
        ];

        $output = $this->controller->store([]);

        self::assertStringContainsString('Choose one of your pets.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithInvalidVetShowsValidationError(): void
    {
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => '999999999',
            'scheduled_at' => (new DateTimeImmutable('+1 week'))->format('Y-m-d\TH:i'),
            'reason' => '',
        ];

        $output = $this->controller->store([]);

        self::assertStringContainsString('Choose a vet.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithEmptyDateTimeShowsValidationError(): void
    {
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => '',
            'reason' => '',
        ];

        $output = $this->controller->store([]);

        self::assertStringContainsString('Choose a date and time.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithPastDateTimeShowsValidationError(): void
    {
        $_POST = [
            'pet_id' => (string) $this->petId,
            'vet_id' => (string) $this->vet->id,
            'scheduled_at' => (new DateTimeImmutable('-1 week'))->format('Y-m-d\TH:i'),
            'reason' => '',
        ];

        $output = $this->controller->store([]);

        self::assertStringContainsString('Choose a time in the future.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Owner;

use App\Domain\Owner;
use App\Domain\Vet;
use App\Http\Controller\Owner\VisitController;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Tests\Support\CreatesTestUsers;
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
    private Owner $owner;
    private Vet $vet;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "visit-owner-{$suffix}@example.test";
        $this->vetEmail = "visit-vet-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->loginAs($this->owner->userId, 'owner');
        $this->controller = new VisitController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
    }

    public function testIndexShowsEmptyStateWithNoVisits(): void
    {
        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringContainsString('Visit History', $output);
        self::assertStringContainsString('No recorded visits yet.', $output);
    }

    public function testIndexListsRecordedVisits(): void
    {
        //arrange
        $pet = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null);
        $appointment = (new AppointmentRepository())->create($pet->id, $this->vet->id, new DateTimeImmutable('-1 week'), 'Checkup');
        Database::connection()
            ->prepare('INSERT INTO visits (appointment_id, diagnosis, notes) VALUES (:appointment_id, :diagnosis, :notes)')
            ->execute(['appointment_id' => $appointment->id, 'diagnosis' => 'Healthy', 'notes' => 'No concerns.']);

        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringContainsString('Visit History', $output);
        self::assertStringContainsString('Healthy', $output);
    }
}

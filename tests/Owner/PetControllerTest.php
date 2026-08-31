<?php

declare(strict_types=1);

namespace App\Tests\Owner;

use App\Domain\Owner;
use App\Http\Controller\Owner\PetController;
use App\Http\TwigFactory;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Tests\Support\CreatesTestUsers;
use App\Tests\Support\HeaderSpy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PetControllerTest extends TestCase
{
    use CreatesTestUsers;

    private PetController $controller;
    private string $ownerEmail;
    private Owner $owner;

    protected function setUp(): void
    {
        HeaderSpy::reset();
        $_POST = [];
        $_FILES = [];
        $this->ownerEmail = sprintf('pet-ctrl-%s@example.test', bin2hex(random_bytes(6)));
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->loginAs($this->owner->userId, 'owner');
        $this->controller = new PetController(TwigFactory::create());
    }

    protected function tearDown(): void
    {
        Database::connection()->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $this->ownerEmail]);
        $_POST = [];
        $_FILES = [];
    }

    public function testIndexListsOwnersPets(): void
    {
        //arrange
        (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', 'Labrador', null);

        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringContainsString('My Pets', $output);
        self::assertStringContainsString('Rex', $output);
    }

    public function testIndexShowsPhotoThumbnailWhenPresent(): void
    {
        //arrange
        (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null, '/uploads/pets/example.png');

        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringContainsString('<img src="/uploads/pets/example.png"', $output);
    }

    public function testStoreWithValidDataCreatesPetAndRedirects(): void
    {
        //arrange
        $_POST = ['name' => 'Milo', 'species' => 'Cat', 'breed' => '', 'birth_date' => ''];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/owner/pets', HeaderSpy::location());
        $pets = (new PetRepository())->findAllByOwnerId($this->owner->id);
        self::assertCount(1, $pets);
        self::assertSame('Milo', $pets[0]->name);
        self::assertNull($pets[0]->photoUrl);
    }

    public function testStoreWithoutNameShowsValidationErrorAndDoesNotCreatePet(): void
    {
        //arrange
        $_POST = ['name' => '', 'species' => 'Cat', 'breed' => '', 'birth_date' => ''];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('Name is required.', $output);
        self::assertSame([], HeaderSpy::$headers);
        self::assertCount(0, (new PetRepository())->findAllByOwnerId($this->owner->id));
    }

    public function testStoreWithInvalidBirthDateShowsValidationError(): void
    {
        //arrange
        $_POST = ['name' => 'Milo', 'species' => 'Cat', 'breed' => '', 'birth_date' => 'not-a-date'];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('Birth date must be a valid date.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithOversizedPhotoShowsValidationErrorAndDoesNotCreatePet(): void
    {
        //arrange
        $_POST = ['name' => 'Milo', 'species' => 'Cat', 'breed' => '', 'birth_date' => ''];
        $_FILES['photo'] = ['error' => UPLOAD_ERR_OK, 'size' => 6 * 1024 * 1024, 'tmp_name' => '/nonexistent'];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('Photo must be smaller than 5 MB.', $output);
        self::assertSame([], HeaderSpy::$headers);
        self::assertCount(0, (new PetRepository())->findAllByOwnerId($this->owner->id));
    }

    public function testProfileShowsPetDetailsAndVisitHistory(): void
    {
        //arrange
        $pet = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', 'Labrador', null);
        $vetEmail = 'pet-ctrl-vet-' . bin2hex(random_bytes(6)) . '@example.test';
        $vet = $this->registerVet($vetEmail);
        $appointment = (new AppointmentRepository())->create($pet->id, $vet->id, new DateTimeImmutable('-1 week'), 'Checkup');
        Database::connection()
            ->prepare('INSERT INTO visits (appointment_id, diagnosis, vaccination, notes) VALUES (:appointment_id, :diagnosis, :vaccination, :notes)')
            ->execute([
                'appointment_id' => $appointment->id,
                'diagnosis' => 'Healthy',
                'vaccination' => 'Rabies booster',
                'notes' => 'No concerns.',
            ]);

        try {
            //act
            $output = $this->controller->profile(['id' => (string) $pet->id]);

            //assert
            self::assertStringContainsString('Rex', $output);
            self::assertStringContainsString('Healthy', $output);
            self::assertStringContainsString('Rabies booster', $output);
        } finally {
            Database::connection()->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $vetEmail]);
        }
    }

    public function testProfileRedirectsForAnotherOwnersPet(): void
    {
        //arrange
        $otherOwnerEmail = 'pet-ctrl-other-' . bin2hex(random_bytes(6)) . '@example.test';
        $otherOwner = $this->registerOwner($otherOwnerEmail);
        $pet = (new PetRepository())->create($otherOwner->id, 'Fido', 'Dog', null, null);

        try {
            //act
            $output = $this->controller->profile(['id' => (string) $pet->id]);

            //assert
            self::assertSame('', $output);
            self::assertSame('/owner/pets', HeaderSpy::location());
        } finally {
            Database::connection()->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $otherOwnerEmail]);
        }
    }
}

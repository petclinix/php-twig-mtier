<?php

declare(strict_types=1);

namespace App\Tests\Owner;

use App\Domain\Owner;
use App\Http\Controller\Owner\PetController;
use App\Infrastructure\Database;
use App\Repository\PetRepository;
use App\Tests\Support\CreatesTestUsers;
use App\Tests\Support\HeaderSpy;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

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
        $this->ownerEmail = sprintf('pet-ctrl-%s@example.test', bin2hex(random_bytes(6)));
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->loginAs($this->owner->userId, 'owner');
        $this->controller = new PetController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));
    }

    protected function tearDown(): void
    {
        Database::connection()->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $this->ownerEmail]);
        $_POST = [];
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
}

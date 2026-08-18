<?php

declare(strict_types=1);

namespace App\Tests\Vet;

use App\Domain\Vet;
use App\Http\Controller\Vet\AvailabilityController;
use App\Infrastructure\Database;
use App\Repository\AvailabilityRepository;
use App\Tests\Support\CreatesTestUsers;
use App\Tests\Support\HeaderSpy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AvailabilityControllerTest extends TestCase
{
    use CreatesTestUsers;

    private AvailabilityController $controller;
    private string $vetEmail;
    private string $otherVetEmail;
    private Vet $vet;
    private Vet $otherVet;

    protected function setUp(): void
    {
        HeaderSpy::reset();
        $_POST = [];
        $suffix = bin2hex(random_bytes(6));
        $this->vetEmail = "avail-vet-{$suffix}@example.test";
        $this->otherVetEmail = "avail-other-{$suffix}@example.test";
        $this->vet = $this->registerVet($this->vetEmail);
        $this->otherVet = $this->registerVet($this->otherVetEmail);
        $this->loginAs($this->vet->userId, 'vet');
        $this->controller = new AvailabilityController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:vet, :other)')
            ->execute(['vet' => $this->vetEmail, 'other' => $this->otherVetEmail]);
        $_POST = [];
    }

    public function testIndexShowsEmptyStateWithNoSlots(): void
    {
        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringContainsString('My Availability', $output);
        self::assertStringContainsString('No availability slots yet.', $output);
    }

    public function testStoreWithValidDataCreatesSlotAndRedirects(): void
    {
        //arrange
        $_POST = [
            'starts_at' => (new DateTimeImmutable('+1 day 09:00'))->format('Y-m-d\TH:i'),
            'ends_at' => (new DateTimeImmutable('+1 day 17:00'))->format('Y-m-d\TH:i'),
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/availability', HeaderSpy::location());
        self::assertCount(1, (new AvailabilityRepository())->findAllByVetId($this->vet->id));
    }

    public function testStoreWithMissingStartShowsValidationError(): void
    {
        //arrange
        $_POST = ['starts_at' => '', 'ends_at' => (new DateTimeImmutable('+1 day 17:00'))->format('Y-m-d\TH:i')];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('Choose a start time.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithMissingEndShowsValidationError(): void
    {
        //arrange
        $_POST = ['starts_at' => (new DateTimeImmutable('+1 day 09:00'))->format('Y-m-d\TH:i'), 'ends_at' => ''];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('Choose an end time.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithEndBeforeStartShowsValidationError(): void
    {
        //arrange
        $_POST = [
            'starts_at' => (new DateTimeImmutable('+1 day 17:00'))->format('Y-m-d\TH:i'),
            'ends_at' => (new DateTimeImmutable('+1 day 09:00'))->format('Y-m-d\TH:i'),
        ];

        //act
        $output = $this->controller->store([]);

        //assert
        self::assertStringContainsString('End time must be after the start time.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testDestroyDeletesOwnSlot(): void
    {
        //arrange
        $slot = (new AvailabilityRepository())->create($this->vet->id, new DateTimeImmutable('+1 day 09:00'), new DateTimeImmutable('+1 day 17:00'));

        //act
        $output = $this->controller->destroy(['id' => (string) $slot->id]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/availability', HeaderSpy::location());
        self::assertNull((new AvailabilityRepository())->findById($slot->id));
    }

    public function testDestroyIsNoOpForAnotherVetsSlot(): void
    {
        //arrange
        $slot = (new AvailabilityRepository())->create($this->otherVet->id, new DateTimeImmutable('+1 day 09:00'), new DateTimeImmutable('+1 day 17:00'));

        //act
        $output = $this->controller->destroy(['id' => (string) $slot->id]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/availability', HeaderSpy::location());
        self::assertNotNull((new AvailabilityRepository())->findById($slot->id));
    }
}

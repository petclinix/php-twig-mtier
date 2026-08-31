<?php

declare(strict_types=1);

namespace App\Tests\Vet;

use App\Domain\DayOfWeek;
use App\Domain\Vet;
use App\Http\Controller\Vet\AvailabilityController;
use App\Http\TwigFactory;
use App\Infrastructure\Database;
use App\Repository\AvailabilityExceptionRepository;
use App\Repository\AvailabilityRepository;
use App\Tests\Support\CreatesTestUsers;
use App\Tests\Support\HeaderSpy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

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
        $this->controller = new AvailabilityController(TwigFactory::create());
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
        self::assertStringContainsString('My Weekly Availability', $output);
        self::assertStringContainsString('No recurring availability set yet.', $output);
    }

    public function testStoreWithValidDataCreatesSlotAndRedirects(): void
    {
        //arrange
        $_POST = ['day_of_week' => 'monday', 'starts_at' => '09:00', 'ends_at' => '17:00'];

        //act
        $output = $this->controller->store();

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/availability', HeaderSpy::location());
        self::assertCount(1, (new AvailabilityRepository())->findAllByVetId($this->vet->id));
    }

    public function testStoreWithMissingDayShowsValidationError(): void
    {
        //arrange
        $_POST = ['day_of_week' => '', 'starts_at' => '09:00', 'ends_at' => '17:00'];

        //act
        $output = $this->controller->store();

        //assert
        self::assertStringContainsString('Choose a day of the week.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithMissingStartShowsValidationError(): void
    {
        //arrange
        $_POST = ['day_of_week' => 'monday', 'starts_at' => '', 'ends_at' => '17:00'];

        //act
        $output = $this->controller->store();

        //assert
        self::assertStringContainsString('Choose a start time.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithMissingEndShowsValidationError(): void
    {
        //arrange
        $_POST = ['day_of_week' => 'monday', 'starts_at' => '09:00', 'ends_at' => ''];

        //act
        $output = $this->controller->store();

        //assert
        self::assertStringContainsString('Choose an end time.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreWithEndBeforeStartShowsValidationError(): void
    {
        //arrange
        $_POST = ['day_of_week' => 'monday', 'starts_at' => '17:00', 'ends_at' => '09:00'];

        //act
        $output = $this->controller->store();

        //assert
        self::assertStringContainsString('End time must be after the start time.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testDestroyDeletesOwnSlot(): void
    {
        //arrange
        $slot = (new AvailabilityRepository())->create($this->vet->id, DayOfWeek::Monday, new DateTimeImmutable('09:00'), new DateTimeImmutable('17:00'));

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
        $slot = (new AvailabilityRepository())->create($this->otherVet->id, DayOfWeek::Monday, new DateTimeImmutable('09:00'), new DateTimeImmutable('17:00'));

        //act
        $output = $this->controller->destroy(['id' => (string) $slot->id]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/availability', HeaderSpy::location());
        self::assertNotNull((new AvailabilityRepository())->findById($slot->id));
    }

    public function testStoreExceptionCreatesBlockingException(): void
    {
        //arrange
        $_POST = ['exception_date' => (new DateTimeImmutable('+1 week'))->format('Y-m-d')];

        //act
        $output = $this->controller->storeException();

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/availability', HeaderSpy::location());
        $exceptions = (new AvailabilityExceptionRepository())->findAllByVetId($this->vet->id);
        self::assertCount(1, $exceptions);
        self::assertFalse($exceptions[0]->isAvailable);
    }

    public function testStoreExceptionCreatesCustomHoursException(): void
    {
        //arrange
        $_POST = [
            'exception_date' => (new DateTimeImmutable('+1 week'))->format('Y-m-d'),
            'is_available' => '1',
            'exception_starts_at' => '10:00',
            'exception_ends_at' => '14:00',
        ];

        //act
        $output = $this->controller->storeException();

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/availability', HeaderSpy::location());
        $exceptions = (new AvailabilityExceptionRepository())->findAllByVetId($this->vet->id);
        self::assertCount(1, $exceptions);
        self::assertTrue($exceptions[0]->isAvailable);
    }

    public function testStoreExceptionWithMissingDateShowsValidationError(): void
    {
        //arrange
        $_POST = ['exception_date' => ''];

        //act
        $output = $this->controller->storeException();

        //assert
        self::assertStringContainsString('Choose a date.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testStoreExceptionWithCustomHoursMissingTimesShowsValidationError(): void
    {
        //arrange
        $_POST = [
            'exception_date' => (new DateTimeImmutable('+1 week'))->format('Y-m-d'),
            'is_available' => '1',
            'exception_starts_at' => '',
            'exception_ends_at' => '',
        ];

        //act
        $output = $this->controller->storeException();

        //assert
        self::assertStringContainsString('Choose a start time.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testDestroyExceptionDeletesOwnException(): void
    {
        //arrange
        $exception = (new AvailabilityExceptionRepository())->create($this->vet->id, new DateTimeImmutable('+1 week'), false, null, null);

        //act
        $output = $this->controller->destroyException(['id' => (string) $exception->id]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/availability', HeaderSpy::location());
        self::assertNull((new AvailabilityExceptionRepository())->findById($exception->id));
    }

    public function testDestroyExceptionIsNoOpForAnotherVetsException(): void
    {
        //arrange
        $exception = (new AvailabilityExceptionRepository())->create($this->otherVet->id, new DateTimeImmutable('+1 week'), false, null, null);

        //act
        $output = $this->controller->destroyException(['id' => (string) $exception->id]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/vet/availability', HeaderSpy::location());
        self::assertNotNull((new AvailabilityExceptionRepository())->findById($exception->id));
    }
}

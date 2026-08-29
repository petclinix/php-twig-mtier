<?php

declare(strict_types=1);

namespace App\Http\Controller\Vet;

use App\Domain\DayOfWeek;
use App\Http\Validation\ErrorBag;
use App\Http\Validation\Input;
use App\Http\Validation\Validate;
use App\Repository\AvailabilityExceptionRepository;
use App\Repository\AvailabilityRepository;
use DateTimeImmutable;
use Twig\Environment;

final class AvailabilityController
{
    use ResolvesVet;

    private const TIME_FORMAT = 'H:i';
    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(private readonly Environment $twig)
    {
    }

    public function index(): string
    {
        $vet = $this->currentVet();

        return $this->render($vet->id, [], []);
    }

    public function store(): string
    {
        $vet = $this->currentVet();

        $errors = new ErrorBag();

        $dayOfWeek = DayOfWeek::tryFrom(Input::string('day_of_week'));
        $errors->addIf($dayOfWeek === null, 'Choose a day of the week.');

        [$startsAt, $endsAt] = $this->validateTimeRange($errors, 'starts_at', 'ends_at');

        if ($errors->isEmpty() && $dayOfWeek !== null && $startsAt !== null && $endsAt !== null) {
            (new AvailabilityRepository())->create($vet->id, $dayOfWeek, $startsAt, $endsAt);

            header('Location: /vet/availability');

            return '';
        }

        return $this->render($vet->id, $errors->all(), $_POST);
    }

    /**
     * @param array<string, string> $vars
     */
    public function destroy(array $vars): string
    {
        $vet = $this->currentVet();
        (new AvailabilityRepository())->delete((int) $vars['id'], $vet->id);

        header('Location: /vet/availability');

        return '';
    }

    public function storeException(): string
    {
        $vet = $this->currentVet();

        $errors = new ErrorBag();

        $dateInput = Input::string('exception_date');
        $date = null;
        if ($dateInput === '') {
            $errors->add('Choose a date.');
        } else {
            $date = Validate::date($dateInput, self::DATE_FORMAT);
            $errors->addIf($date === null, 'Date must be valid.');
        }

        $isAvailable = Input::string('is_available') === '1';
        $startsAt = null;
        $endsAt = null;

        if ($isAvailable) {
            [$startsAt, $endsAt] = $this->validateTimeRange($errors, 'exception_starts_at', 'exception_ends_at');
        }

        if ($errors->isEmpty() && $date !== null) {
            (new AvailabilityExceptionRepository())->create($vet->id, $date, $isAvailable, $startsAt, $endsAt);

            header('Location: /vet/availability');

            return '';
        }

        return $this->render($vet->id, $errors->all(), $_POST);
    }

    /**
     * @param array<string, string> $vars
     */
    public function destroyException(array $vars): string
    {
        $vet = $this->currentVet();
        (new AvailabilityExceptionRepository())->delete((int) $vars['id'], $vet->id);

        header('Location: /vet/availability');

        return '';
    }

    /**
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private function validateTimeRange(ErrorBag $errors, string $startField, string $endField): array
    {
        $startsAtInput = Input::string($startField);
        $startsAt = null;
        if ($startsAtInput === '') {
            $errors->add('Choose a start time.');
        } else {
            $startsAt = Validate::date($startsAtInput, self::TIME_FORMAT);
            $errors->addIf($startsAt === null, 'Start time must be valid.');
        }

        $endsAtInput = Input::string($endField);
        $endsAt = null;
        if ($endsAtInput === '') {
            $errors->add('Choose an end time.');
        } else {
            $endsAt = Validate::date($endsAtInput, self::TIME_FORMAT);
            $errors->addIf($endsAt === null, 'End time must be valid.');
        }

        $errors->addIf($startsAt !== null && $endsAt !== null && $endsAt <= $startsAt, 'End time must be after the start time.');

        return [$startsAt, $endsAt];
    }

    /**
     * @param list<string> $errors
     * @param array<string, string> $old
     */
    private function render(int $vetId, array $errors, array $old): string
    {
        $slots = (new AvailabilityRepository())->findAllByVetId($vetId);
        $exceptions = (new AvailabilityExceptionRepository())->findAllByVetId($vetId);

        return $this->twig->render('vet/availability/index.html.twig', [
            'slots' => $slots,
            'exceptions' => $exceptions,
            'errors' => $errors,
            'old' => $old,
        ]);
    }
}

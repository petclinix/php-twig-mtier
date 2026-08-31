<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Domain\Appointment;
use App\Http\Validation\ErrorBag;
use App\Http\Validation\Input;
use App\Http\Validation\Validate;
use App\Repository\AppointmentRepository;
use App\Repository\Exception\AppointmentSlotUnavailableException;
use App\Repository\PetRepository;
use App\Repository\VetRepository;
use App\Service\Exception\AppointmentNotCancellableException;
use App\Service\OwnerAppointmentBoardService;
use App\Service\ServiceFactory;
use DateTimeImmutable;
use Twig\Environment;

final class AppointmentController
{
    use ResolvesOwner;

    private const SLOT_FORMAT = 'Y-m-d\TH:i';

    /** @var list<int> */
    private const ALLOWED_DURATIONS = [15, 30, 45, 60, 90];
    private const DEFAULT_DURATION_MINUTES = 30;

    public function __construct(
        private readonly Environment $twig,
        private readonly ServiceFactory $services = new ServiceFactory(),
    ) {}

    public function index(): string
    {
        return $this->render(
            [],
            [],
            Input::queryInt('vet_id'),
            Input::queryInt('duration_minutes', self::DEFAULT_DURATION_MINUTES),
        );
    }

    public function store(): string
    {
        $owner = $this->currentOwner();
        $petsById = [];
        foreach ((new PetRepository())->findAllByOwnerId($owner->id) as $pet) {
            $petsById[$pet->id] = $pet;
        }

        $errors = new ErrorBag();

        $petId = Input::int('pet_id');
        $errors->addIf(!isset($petsById[$petId]), 'Choose one of your pets.');

        $vetId = Input::int('vet_id');
        $vet = (new VetRepository())->findById($vetId);
        $errors->addIf($vet === null, 'Choose a vet.');

        $durationMinutes = Input::int('duration_minutes', self::DEFAULT_DURATION_MINUTES);
        $errors->addIf(!in_array($durationMinutes, self::ALLOWED_DURATIONS, true), 'Choose a valid duration.');

        $scheduledAtInput = Input::string('scheduled_at');
        $scheduledAt = null;
        if ($scheduledAtInput === '') {
            $errors->add('Choose a date and time.');
        } else {
            $scheduledAt = Validate::date($scheduledAtInput, self::SLOT_FORMAT);
            if ($scheduledAt === null) {
                $errors->add('Date and time must be valid.');
            } elseif ($scheduledAt < new DateTimeImmutable('now')) {
                $errors->add('Choose a time in the future.');
            } elseif ($vet !== null && !$this->services->appointmentAvailabilityService()->isOfferedSlot($vet->id, $scheduledAt, $durationMinutes)) {
                $errors->add('That time is no longer available. Please choose another.');
            }
        }

        $reason = Input::string('reason');

        if ($errors->isEmpty() && $scheduledAt !== null) {
            try {
                (new AppointmentRepository())->create($petId, $vetId, $scheduledAt, $reason !== '' ? $reason : null, $durationMinutes);

                header('Location: /owner/appointments');

                return '';
            } catch (AppointmentSlotUnavailableException) {
                $errors->add('That time is no longer available. Please choose another.');
            }
        }

        return $this->render($errors->all(), $_POST, $vetId, $durationMinutes);
    }

    /**
     * @param array<string, string> $vars
     */
    public function cancel(array $vars): string
    {
        $owner = $this->currentOwner();
        $this->services->appointmentTransitionService()->cancelAsOwner((int) $vars['id'], $owner->id);

        header('Location: /owner/appointments');

        return '';
    }

    /**
     * @param array<string, string> $vars
     */
    public function editReschedule(array $vars): string
    {
        $owner = $this->currentOwner();
        $appointment = $this->authorizedAppointment((int) $vars['id'], $owner->id);

        if ($appointment === null) {
            header('Location: /owner/appointments');

            return '';
        }

        return $this->renderReschedule($appointment, [], [], Input::queryInt('duration_minutes', $appointment->durationMinutes));
    }

    /**
     * @param array<string, string> $vars
     */
    public function reschedule(array $vars): string
    {
        $owner = $this->currentOwner();
        $appointmentId = (int) $vars['id'];
        $appointment = $this->authorizedAppointment($appointmentId, $owner->id);

        if ($appointment === null) {
            header('Location: /owner/appointments');

            return '';
        }

        $errors = new ErrorBag();

        $durationMinutes = Input::int('duration_minutes', self::DEFAULT_DURATION_MINUTES);
        $errors->addIf(!in_array($durationMinutes, self::ALLOWED_DURATIONS, true), 'Choose a valid duration.');

        $scheduledAtInput = Input::string('scheduled_at');
        $scheduledAt = null;
        if ($scheduledAtInput === '') {
            $errors->add('Choose a date and time.');
        } else {
            $scheduledAt = Validate::date($scheduledAtInput, self::SLOT_FORMAT);
            if ($scheduledAt === null) {
                $errors->add('Date and time must be valid.');
            } elseif ($scheduledAt < new DateTimeImmutable('now')) {
                $errors->add('Choose a time in the future.');
            } elseif (!$this->services->appointmentAvailabilityService()->isOfferedSlot($appointment->vetId, $scheduledAt, $durationMinutes)) {
                $errors->add('That time is no longer available. Please choose another.');
            }
        }

        $reason = Input::string('reason');

        if ($errors->isEmpty() && $scheduledAt !== null) {
            try {
                $this->services->appointmentTransitionService()->rescheduleAsOwner(
                    $appointmentId,
                    $owner->id,
                    $scheduledAt,
                    $durationMinutes,
                    $reason !== '' ? $reason : null,
                );

                header('Location: /owner/appointments');

                return '';
            } catch (AppointmentNotCancellableException) {
                $errors->add('This appointment can no longer be cancelled or rescheduled.');
            } catch (AppointmentSlotUnavailableException) {
                $errors->add('That time is no longer available. Please choose another.');
            }
        }

        return $this->renderReschedule($appointment, $errors->all(), $_POST, $durationMinutes);
    }

    /**
     * @param list<string> $errors
     * @param array<string, string> $old
     */
    private function renderReschedule(Appointment $appointment, array $errors, array $old, int $requestedDuration): string
    {
        $selectedDuration = in_array($requestedDuration, self::ALLOWED_DURATIONS, true)
            ? $requestedDuration
            : self::DEFAULT_DURATION_MINUTES;

        $slotOptions = array_map(
            static fn(DateTimeImmutable $slot): array => [
                'value' => $slot->format(self::SLOT_FORMAT),
                'label' => $slot->format('Y-m-d H:i'),
            ],
            $this->services->appointmentAvailabilityService()->openSlots($appointment->vetId, $selectedDuration),
        );

        return $this->twig->render('owner/appointments/reschedule.html.twig', [
            'appointment' => $appointment,
            'slotOptions' => $slotOptions,
            'selectedDuration' => $selectedDuration,
            'allowedDurations' => self::ALLOWED_DURATIONS,
            'errors' => $errors,
            'old' => $old,
        ]);
    }

    private function authorizedAppointment(int $appointmentId, int $ownerId): ?Appointment
    {
        $appointment = (new AppointmentRepository())->findById($appointmentId);

        if ($appointment === null) {
            return null;
        }

        $pet = (new PetRepository())->findById($appointment->petId);

        return ($pet !== null && $pet->ownerId === $ownerId) ? $appointment : null;
    }

    /**
     * @param list<string> $errors
     * @param array<string, string> $old
     */
    private function render(array $errors, array $old = [], int $selectedVetId = 0, int $requestedDuration = self::DEFAULT_DURATION_MINUTES): string
    {
        $owner = $this->currentOwner();
        $selectedDuration = in_array($requestedDuration, self::ALLOWED_DURATIONS, true)
            ? $requestedDuration
            : self::DEFAULT_DURATION_MINUTES;
        $board = $this->boardService()->forOwner($owner->id, $selectedVetId, $selectedDuration);

        return $this->twig->render('owner/appointments/index.html.twig', $board + [
            'errors' => $errors,
            'old' => $old,
            'selectedVetId' => $selectedVetId,
            'selectedDuration' => $selectedDuration,
            'allowedDurations' => self::ALLOWED_DURATIONS,
        ]);
    }

    private function boardService(): OwnerAppointmentBoardService
    {
        return $this->services->ownerAppointmentBoardService();
    }
}

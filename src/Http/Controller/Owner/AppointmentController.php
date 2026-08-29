<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Http\Validation\ErrorBag;
use App\Http\Validation\Input;
use App\Http\Validation\Validate;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Repository\VetRepository;
use App\Service\OwnerAppointmentBoardService;
use App\Service\ServiceFactory;
use DateTimeImmutable;
use Twig\Environment;

final class AppointmentController
{
    use ResolvesOwner;

    private const SLOT_FORMAT = 'Y-m-d\TH:i';

    public function __construct(
        private readonly Environment $twig,
        private readonly ServiceFactory $services = new ServiceFactory(),
    ) {
    }

    public function index(): string
    {
        return $this->render([], [], Input::queryInt('vet_id'));
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
            } elseif ($vet !== null && !$this->boardService()->isOfferedSlot($vet->id, $scheduledAt)) {
                $errors->add('That time is no longer available. Please choose another.');
            }
        }

        $reason = Input::string('reason');

        if ($errors->isEmpty() && $scheduledAt !== null) {
            (new AppointmentRepository())->create($petId, $vetId, $scheduledAt, $reason !== '' ? $reason : null);

            header('Location: /owner/appointments');

            return '';
        }

        return $this->render($errors->all(), $_POST, $vetId);
    }

    /**
     * @param list<string> $errors
     * @param array<string, string> $old
     */
    private function render(array $errors, array $old = [], int $selectedVetId = 0): string
    {
        $owner = $this->currentOwner();
        $board = $this->boardService()->forOwner($owner->id, $selectedVetId);

        return $this->twig->render('owner/appointments/index.html.twig', $board + [
            'errors' => $errors,
            'old' => $old,
            'selectedVetId' => $selectedVetId,
        ]);
    }

    private function boardService(): OwnerAppointmentBoardService
    {
        return $this->services->ownerAppointmentBoardService();
    }
}

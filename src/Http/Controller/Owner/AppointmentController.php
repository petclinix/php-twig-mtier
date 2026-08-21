<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Http\Controller\IndexesById;
use App\Http\Validation\ErrorBag;
use App\Http\Validation\Input;
use App\Http\Validation\Validate;
use App\Repository\AppointmentRepository;
use App\Repository\AvailabilityRepository;
use App\Repository\PetRepository;
use App\Repository\VetRepository;
use App\Service\AppointmentAvailabilityService;
use DateTimeImmutable;
use Twig\Environment;

final class AppointmentController
{
    use ResolvesOwner;
    use IndexesById;

    private const SLOT_FORMAT = 'Y-m-d\TH:i';

    public function __construct(private readonly Environment $twig)
    {
    }

    public function index(): string
    {
        return $this->render([], [], Input::queryInt('vet_id'));
    }

    public function store(): string
    {
        $owner = $this->currentOwner();
        $petsById = $this->indexById((new PetRepository())->findAllByOwnerId($owner->id));

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
            } elseif ($vet !== null && !$this->isOfferedSlot($vet->id, $scheduledAt)) {
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

    private function isOfferedSlot(int $vetId, DateTimeImmutable $scheduledAt): bool
    {
        $service = new AppointmentAvailabilityService(new AvailabilityRepository(), new AppointmentRepository());
        $target = $scheduledAt->format(self::SLOT_FORMAT);

        foreach ($service->openSlots($vetId) as $slot) {
            if ($slot->format(self::SLOT_FORMAT) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $errors
     * @param array<string, string> $old
     */
    private function render(array $errors, array $old = [], int $selectedVetId = 0): string
    {
        $owner = $this->currentOwner();
        $pets = (new PetRepository())->findAllByOwnerId($owner->id);
        $petsById = $this->indexById($pets);
        $vets = (new VetRepository())->findAll();
        $vetsById = $this->indexById($vets);
        $appointments = (new AppointmentRepository())->findAllByPetIds(array_keys($petsById));

        $slotOptions = [];
        if ($selectedVetId > 0 && isset($vetsById[$selectedVetId])) {
            $service = new AppointmentAvailabilityService(new AvailabilityRepository(), new AppointmentRepository());
            $slotOptions = array_map(
                static fn (DateTimeImmutable $slot): array => [
                    'value' => $slot->format(self::SLOT_FORMAT),
                    'label' => $slot->format('Y-m-d H:i'),
                ],
                $service->openSlots($selectedVetId),
            );
        }

        return $this->twig->render('owner/appointments/index.html.twig', [
            'pets' => $pets,
            'vets' => $vets,
            'appointments' => $appointments,
            'petsById' => $petsById,
            'vetsById' => $vetsById,
            'errors' => $errors,
            'old' => $old,
            'selectedVetId' => $selectedVetId,
            'slotOptions' => $slotOptions,
        ]);
    }
}

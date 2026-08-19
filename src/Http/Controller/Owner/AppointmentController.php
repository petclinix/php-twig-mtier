<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Http\Controller\IndexesById;
use App\Http\Validation\ErrorBag;
use App\Http\Validation\Input;
use App\Http\Validation\Validate;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Repository\VetRepository;
use DateTimeImmutable;
use Twig\Environment;

final class AppointmentController
{
    use ResolvesOwner;
    use IndexesById;

    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function index(array $vars): string
    {
        return $this->render([]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function store(array $vars): string
    {
        $owner = $this->currentOwner();
        $petsById = $this->indexById((new PetRepository())->findAllByOwnerId($owner->id));

        $errors = new ErrorBag();

        $petId = Input::int('pet_id');
        $errors->addIf(!isset($petsById[$petId]), 'Choose one of your pets.');

        $vetId = Input::int('vet_id');
        $errors->addIf((new VetRepository())->findById($vetId) === null, 'Choose a vet.');

        $scheduledAtInput = Input::string('scheduled_at');
        $scheduledAt = null;
        if ($scheduledAtInput === '') {
            $errors->add('Choose a date and time.');
        } else {
            $scheduledAt = Validate::date($scheduledAtInput, 'Y-m-d\TH:i');
            if ($scheduledAt === null) {
                $errors->add('Date and time must be valid.');
            } elseif ($scheduledAt < new DateTimeImmutable('now')) {
                $errors->add('Choose a time in the future.');
            }
        }

        $reason = Input::string('reason');

        if ($errors->isEmpty() && $scheduledAt !== null) {
            (new AppointmentRepository())->create($petId, $vetId, $scheduledAt, $reason !== '' ? $reason : null);

            header('Location: /owner/appointments');

            return '';
        }

        return $this->render($errors->all(), $_POST);
    }

    /**
     * @param list<string> $errors
     * @param array<string, string> $old
     */
    private function render(array $errors, array $old = []): string
    {
        $owner = $this->currentOwner();
        $pets = (new PetRepository())->findAllByOwnerId($owner->id);
        $petsById = $this->indexById($pets);
        $vets = (new VetRepository())->findAll();
        $vetsById = $this->indexById($vets);
        $appointments = (new AppointmentRepository())->findAllByPetIds(array_keys($petsById));

        return $this->twig->render('owner/appointments/index.html.twig', [
            'pets' => $pets,
            'vets' => $vets,
            'appointments' => $appointments,
            'petsById' => $petsById,
            'vetsById' => $vetsById,
            'errors' => $errors,
            'old' => $old,
        ]);
    }
}

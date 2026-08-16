<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Http\Controller\IndexesById;
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

        $petId = (int) ($_POST['pet_id'] ?? 0);
        $vetId = (int) ($_POST['vet_id'] ?? 0);
        $scheduledAtInput = trim((string) ($_POST['scheduled_at'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        $errors = [];

        if (!isset($petsById[$petId])) {
            $errors[] = 'Choose one of your pets.';
        }
        if ((new VetRepository())->findById($vetId) === null) {
            $errors[] = 'Choose a vet.';
        }

        $scheduledAt = null;
        if ($scheduledAtInput === '') {
            $errors[] = 'Choose a date and time.';
        } else {
            $scheduledAt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $scheduledAtInput) ?: null;
            if ($scheduledAt === null) {
                $errors[] = 'Date and time must be valid.';
            } elseif ($scheduledAt < new DateTimeImmutable('now')) {
                $errors[] = 'Choose a time in the future.';
            }
        }

        if ($errors === [] && $scheduledAt !== null) {
            (new AppointmentRepository())->create($petId, $vetId, $scheduledAt, $reason !== '' ? $reason : null);

            header('Location: /owner/appointments');

            return '';
        }

        return $this->render($errors, $_POST);
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

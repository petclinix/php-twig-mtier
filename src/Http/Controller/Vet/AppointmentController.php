<?php

declare(strict_types=1);

namespace App\Http\Controller\Vet;

use App\Domain\AppointmentStatus;
use App\Repository\AppointmentRepository;
use App\Repository\OwnerRepository;
use App\Repository\PetRepository;
use Twig\Environment;

final class AppointmentController
{
    use ResolvesVet;

    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function index(array $vars): string
    {
        $vet = $this->currentVet();
        $appointments = (new AppointmentRepository())->findAllByVetId($vet->id);

        $petRepository = new PetRepository();
        $ownerRepository = new OwnerRepository();

        $petsById = [];
        $ownersById = [];
        foreach ($appointments as $appointment) {
            if (isset($petsById[$appointment->petId])) {
                continue;
            }

            $pet = $petRepository->findById($appointment->petId);
            if ($pet === null) {
                continue;
            }

            $petsById[$pet->id] = $pet;

            if (!isset($ownersById[$pet->ownerId])) {
                $owner = $ownerRepository->findById($pet->ownerId);
                if ($owner !== null) {
                    $ownersById[$owner->id] = $owner;
                }
            }
        }

        return $this->twig->render('vet/appointments/index.html.twig', [
            'appointments' => $appointments,
            'petsById' => $petsById,
            'ownersById' => $ownersById,
        ]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function confirm(array $vars): string
    {
        return $this->transition($vars, [AppointmentStatus::Requested], AppointmentStatus::Confirmed);
    }

    /**
     * @param array<string, string> $vars
     */
    public function cancel(array $vars): string
    {
        return $this->transition($vars, [AppointmentStatus::Requested, AppointmentStatus::Confirmed], AppointmentStatus::Cancelled);
    }

    /**
     * @param array<string, string> $vars
     * @param list<AppointmentStatus> $allowedFrom
     */
    private function transition(array $vars, array $allowedFrom, AppointmentStatus $to): string
    {
        $vet = $this->currentVet();
        $appointmentId = (int) $vars['id'];
        $repository = new AppointmentRepository();
        $appointment = $repository->findById($appointmentId);

        if ($appointment !== null && $appointment->vetId === $vet->id && in_array($appointment->status, $allowedFrom, true)) {
            $repository->updateStatus($appointmentId, $to);
        }

        header('Location: /vet/appointments');

        return '';
    }
}

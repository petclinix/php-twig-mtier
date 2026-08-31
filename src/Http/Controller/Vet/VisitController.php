<?php

declare(strict_types=1);

namespace App\Http\Controller\Vet;

use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Service\ServiceFactory;
use Twig\Environment;

final class VisitController
{
    use ResolvesVet;

    public function __construct(
        private readonly Environment $twig,
        private readonly ServiceFactory $services = new ServiceFactory(),
    ) {}

    /**
     * @param array<string, string> $vars
     */
    public function create(array $vars): string
    {
        $appointment = $this->recordableAppointment((int) $vars['id']);

        if ($appointment === null) {
            header('Location: /vet/appointments');

            return '';
        }

        return $this->twig->render('vet/visits/create.html.twig', [
            'appointment' => $appointment,
            'pet' => (new PetRepository())->findById($appointment->petId),
            'old' => [],
        ]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function store(array $vars): string
    {
        $appointment = $this->recordableAppointment((int) $vars['id']);

        if ($appointment === null) {
            header('Location: /vet/appointments');

            return '';
        }

        $diagnosis = trim((string) ($_POST['diagnosis'] ?? ''));
        $vaccination = trim((string) ($_POST['vaccination'] ?? ''));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        $this->services->visitService()->recordVisit(
            $appointment,
            $diagnosis !== '' ? $diagnosis : null,
            $vaccination !== '' ? $vaccination : null,
            $notes !== '' ? $notes : null,
        );

        header('Location: /vet/appointments');

        return '';
    }

    private function recordableAppointment(int $appointmentId): ?Appointment
    {
        $vet = $this->currentVet();
        $appointment = (new AppointmentRepository())->findById($appointmentId);

        if ($appointment === null || $appointment->vetId !== $vet->id) {
            return null;
        }

        return $appointment->status === AppointmentStatus::Confirmed ? $appointment : null;
    }
}

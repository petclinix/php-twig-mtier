<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\AppointmentStatus;
use App\Repository\AppointmentRepository;

final class AppointmentTransitionService
{
    public function __construct(private readonly AppointmentRepository $appointments)
    {
    }

    /**
     * @param list<AppointmentStatus> $allowedFrom
     */
    public function transition(int $appointmentId, int $vetId, array $allowedFrom, AppointmentStatus $to): bool
    {
        $appointment = $this->appointments->findById($appointmentId);

        if ($appointment === null || $appointment->vetId !== $vetId || !in_array($appointment->status, $allowedFrom, true)) {
            return false;
        }

        $this->appointments->updateStatus($appointmentId, $to);

        return true;
    }
}

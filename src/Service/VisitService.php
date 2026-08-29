<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Domain\Visit;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\VisitRepository;

final class VisitService
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly VisitRepository $visits,
    ) {
    }

    public function recordVisit(Appointment $appointment, ?string $diagnosis, ?string $vaccination, ?string $notes): Visit
    {
        return Database::runInTransaction(function () use ($appointment, $diagnosis, $vaccination, $notes): Visit {
            $visit = $this->visits->create($appointment->id, $diagnosis, $vaccination, $notes);
            $this->appointments->updateStatus($appointment->id, AppointmentStatus::Completed);

            return $visit;
        });
    }
}

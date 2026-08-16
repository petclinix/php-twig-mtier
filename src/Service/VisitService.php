<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Domain\Visit;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\VisitRepository;
use Throwable;

final class VisitService
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly VisitRepository $visits,
    ) {
    }

    public function recordVisit(Appointment $appointment, ?string $diagnosis, ?string $notes): Visit
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $visit = $this->visits->create($appointment->id, $diagnosis, $notes);
            $this->appointments->updateStatus($appointment->id, AppointmentStatus::Completed);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $visit;
    }
}

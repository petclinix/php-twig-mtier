<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\AppointmentStatus;
use App\Domain\Stats;

final class StatsRepository extends AbstractRepository
{
    public function summary(): Stats
    {
        $ownerCount = (int) $this->pdo->query('SELECT COUNT(*) FROM owners')->fetchColumn();
        $vetCount = (int) $this->pdo->query('SELECT COUNT(*) FROM vets')->fetchColumn();
        $petCount = (int) $this->pdo->query('SELECT COUNT(*) FROM pets')->fetchColumn();
        $visitCount = (int) $this->pdo->query('SELECT COUNT(*) FROM visits')->fetchColumn();

        $appointmentsByStatus = [];
        foreach (AppointmentStatus::cases() as $status) {
            $appointmentsByStatus[$status->value] = 0;
        }

        $stmt = $this->pdo->query('SELECT status, COUNT(*) AS total FROM appointments GROUP BY status');
        foreach ($stmt->fetchAll() as $row) {
            $appointmentsByStatus[(string) $row['status']] = (int) $row['total'];
        }

        return new Stats(
            $ownerCount,
            $vetCount,
            $petCount,
            array_sum($appointmentsByStatus),
            $appointmentsByStatus,
            $visitCount,
        );
    }
}

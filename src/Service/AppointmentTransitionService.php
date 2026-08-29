<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Appointment;
use App\Domain\AppointmentStatus;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Service\Exception\AppointmentNotCancellableException;
use DateTimeImmutable;

final class AppointmentTransitionService
{
    private const CANCELLATION_CUTOFF_HOURS = 2;

    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly PetRepository $pets,
    ) {
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

        if ($to === AppointmentStatus::Cancelled && !$this->isBeforeCutoff($appointment)) {
            return false;
        }

        $this->appointments->updateStatus($appointmentId, $to);

        return true;
    }

    public function cancelAsOwner(int $appointmentId, int $ownerId): bool
    {
        try {
            $appointment = $this->authorizeOwnerCancellation($appointmentId, $ownerId);
        } catch (AppointmentNotCancellableException) {
            return false;
        }

        $this->appointments->updateStatus($appointment->id, AppointmentStatus::Cancelled);

        return true;
    }

    public function rescheduleAsOwner(
        int $appointmentId,
        int $ownerId,
        DateTimeImmutable $newScheduledAt,
        int $newDurationMinutes,
        ?string $reason,
    ): Appointment {
        return Database::runInTransaction(function () use ($appointmentId, $ownerId, $newScheduledAt, $newDurationMinutes, $reason): Appointment {
            $appointment = $this->authorizeOwnerCancellation($appointmentId, $ownerId);

            $this->appointments->updateStatus($appointment->id, AppointmentStatus::Cancelled);

            return $this->appointments->create(
                $appointment->petId,
                $appointment->vetId,
                $newScheduledAt,
                $reason,
                $newDurationMinutes,
            );
        });
    }

    private function authorizeOwnerCancellation(int $appointmentId, int $ownerId): Appointment
    {
        $appointment = $this->appointments->findById($appointmentId);

        if ($appointment === null) {
            throw new AppointmentNotCancellableException();
        }

        $pet = $this->pets->findById($appointment->petId);

        if ($pet === null || $pet->ownerId !== $ownerId) {
            throw new AppointmentNotCancellableException();
        }

        if (!in_array($appointment->status, [AppointmentStatus::Requested, AppointmentStatus::Confirmed], true)) {
            throw new AppointmentNotCancellableException();
        }

        if (!$this->isBeforeCutoff($appointment)) {
            throw new AppointmentNotCancellableException();
        }

        return $appointment;
    }

    private function isBeforeCutoff(Appointment $appointment): bool
    {
        return new DateTimeImmutable('now') < $appointment->scheduledAt->modify('-' . self::CANCELLATION_CUTOFF_HOURS . ' hours');
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Appointment;
use App\Domain\Owner;
use App\Domain\Pet;
use App\Repository\AppointmentRepository;
use App\Repository\OwnerRepository;
use App\Repository\PetRepository;

final class VetAppointmentBoardService
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly PetRepository $pets,
        private readonly OwnerRepository $owners,
    ) {}

    /**
     * @return array{
     *     appointments: list<Appointment>,
     *     petsById: array<int, Pet>,
     *     ownersById: array<int, Owner>,
     * }
     */
    public function forVet(int $vetId): array
    {
        $appointments = $this->appointments->findAllByVetId($vetId);

        $petsById = [];
        $ownersById = [];
        foreach ($appointments as $appointment) {
            if (isset($petsById[$appointment->petId])) {
                continue;
            }

            $pet = $this->pets->findById($appointment->petId);
            if ($pet === null) {
                continue;
            }

            $petsById[$pet->id] = $pet;

            if (!isset($ownersById[$pet->ownerId])) {
                $owner = $this->owners->findById($pet->ownerId);
                if ($owner !== null) {
                    $ownersById[$owner->id] = $owner;
                }
            }
        }

        return [
            'appointments' => $appointments,
            'petsById' => $petsById,
            'ownersById' => $ownersById,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Appointment;
use App\Domain\Pet;
use App\Domain\Vet;
use App\Domain\Visit;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Repository\VetRepository;
use App\Repository\VisitRepository;

final class OwnerVisitBoardService
{
    public function __construct(
        private readonly PetRepository $pets,
        private readonly VetRepository $vets,
        private readonly AppointmentRepository $appointments,
        private readonly VisitRepository $visits,
    ) {
    }

    /**
     * @return array{
     *     visits: list<Visit>,
     *     appointmentsById: array<int, Appointment>,
     *     petsById: array<int, Pet>,
     *     vetsById: array<int, Vet>,
     * }
     */
    public function forOwner(int $ownerId): array
    {
        $petsById = $this->indexById($this->pets->findAllByOwnerId($ownerId));
        $petIds = array_keys($petsById);

        return [
            'visits' => $this->visits->findAllByPetIds($petIds),
            'appointmentsById' => $this->indexById($this->appointments->findAllByPetIds($petIds)),
            'petsById' => $petsById,
            'vetsById' => $this->indexById($this->vets->findAll()),
        ];
    }

    /**
     * @param list<object{id: int}> $items
     * @return array<int, object>
     */
    private function indexById(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $indexed[$item->id] = $item;
        }

        return $indexed;
    }
}

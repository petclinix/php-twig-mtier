<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Appointment;
use App\Domain\Pet;
use App\Domain\Vet;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Repository\VetRepository;
use DateTimeImmutable;

final class OwnerAppointmentBoardService
{
    private const SLOT_FORMAT = 'Y-m-d\TH:i';

    public function __construct(
        private readonly PetRepository $pets,
        private readonly VetRepository $vets,
        private readonly AppointmentRepository $appointments,
        private readonly AppointmentAvailabilityService $availability,
    ) {
    }

    /**
     * @return array{
     *     pets: list<Pet>,
     *     vets: list<Vet>,
     *     appointments: list<Appointment>,
     *     petsById: array<int, Pet>,
     *     vetsById: array<int, Vet>,
     *     slotOptions: list<array{value: string, label: string}>,
     * }
     */
    public function forOwner(int $ownerId, int $selectedVetId, int $durationMinutes): array
    {
        $pets = $this->pets->findAllByOwnerId($ownerId);
        $petsById = $this->indexById($pets);
        $vets = $this->vets->findAll();
        $vetsById = $this->indexById($vets);
        $appointments = $this->appointments->findAllByPetIds(array_keys($petsById));

        $slotOptions = [];
        if ($selectedVetId > 0 && isset($vetsById[$selectedVetId])) {
            $slotOptions = array_map(
                static fn (DateTimeImmutable $slot): array => [
                    'value' => $slot->format(self::SLOT_FORMAT),
                    'label' => $slot->format('Y-m-d H:i'),
                ],
                $this->availability->openSlots($selectedVetId, $durationMinutes),
            );
        }

        return compact('pets', 'vets', 'appointments', 'petsById', 'vetsById', 'slotOptions');
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

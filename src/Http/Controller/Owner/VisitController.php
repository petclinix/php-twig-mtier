<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Http\Controller\IndexesById;
use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Repository\VetRepository;
use App\Repository\VisitRepository;
use Twig\Environment;

final class VisitController
{
    use ResolvesOwner;
    use IndexesById;

    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function index(array $vars): string
    {
        $owner = $this->currentOwner();
        $pets = (new PetRepository())->findAllByOwnerId($owner->id);
        $petsById = $this->indexById($pets);
        $petIds = array_keys($petsById);

        $vetsById = $this->indexById((new VetRepository())->findAll());
        $appointmentsById = $this->indexById((new AppointmentRepository())->findAllByPetIds($petIds));
        $visits = (new VisitRepository())->findAllByPetIds($petIds);

        return $this->twig->render('owner/visits/index.html.twig', [
            'visits' => $visits,
            'appointmentsById' => $appointmentsById,
            'petsById' => $petsById,
            'vetsById' => $vetsById,
        ]);
    }
}

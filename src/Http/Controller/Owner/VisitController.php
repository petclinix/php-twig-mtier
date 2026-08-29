<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Repository\AppointmentRepository;
use App\Repository\PetRepository;
use App\Repository\VetRepository;
use App\Repository\VisitRepository;
use App\Service\OwnerVisitBoardService;
use Twig\Environment;

final class VisitController
{
    use ResolvesOwner;

    public function __construct(private readonly Environment $twig)
    {
    }

    public function index(): string
    {
        $owner = $this->currentOwner();
        $board = (new OwnerVisitBoardService(
            new PetRepository(),
            new VetRepository(),
            new AppointmentRepository(),
            new VisitRepository(),
        ))->forOwner($owner->id);

        return $this->twig->render('owner/visits/index.html.twig', $board);
    }
}

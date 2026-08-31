<?php

declare(strict_types=1);

namespace App\Http\Controller\Owner;

use App\Service\ServiceFactory;
use Twig\Environment;

final class VisitController
{
    use ResolvesOwner;

    public function __construct(
        private readonly Environment $twig,
        private readonly ServiceFactory $services = new ServiceFactory(),
    ) {}

    public function index(): string
    {
        $owner = $this->currentOwner();
        $board = $this->services->ownerVisitBoardService()->forOwner($owner->id);

        return $this->twig->render('owner/visits/index.html.twig', $board);
    }
}

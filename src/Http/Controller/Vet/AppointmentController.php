<?php

declare(strict_types=1);

namespace App\Http\Controller\Vet;

use App\Domain\AppointmentStatus;
use App\Repository\AppointmentRepository;
use App\Repository\OwnerRepository;
use App\Repository\PetRepository;
use App\Service\AppointmentTransitionService;
use App\Service\VetAppointmentBoardService;
use Twig\Environment;

final class AppointmentController
{
    use ResolvesVet;

    public function __construct(private readonly Environment $twig)
    {
    }

    public function index(): string
    {
        $vet = $this->currentVet();
        $board = (new VetAppointmentBoardService(
            new AppointmentRepository(),
            new PetRepository(),
            new OwnerRepository(),
        ))->forVet($vet->id);

        return $this->twig->render('vet/appointments/index.html.twig', $board);
    }

    /**
     * @param array<string, string> $vars
     */
    public function confirm(array $vars): string
    {
        return $this->transition($vars, [AppointmentStatus::Requested], AppointmentStatus::Confirmed);
    }

    /**
     * @param array<string, string> $vars
     */
    public function cancel(array $vars): string
    {
        return $this->transition($vars, [AppointmentStatus::Requested, AppointmentStatus::Confirmed], AppointmentStatus::Cancelled);
    }

    /**
     * @param array<string, string> $vars
     * @param list<AppointmentStatus> $allowedFrom
     */
    private function transition(array $vars, array $allowedFrom, AppointmentStatus $to): string
    {
        $vet = $this->currentVet();
        (new AppointmentTransitionService(new AppointmentRepository()))
            ->transition((int) $vars['id'], $vet->id, $allowedFrom, $to);

        header('Location: /vet/appointments');

        return '';
    }
}

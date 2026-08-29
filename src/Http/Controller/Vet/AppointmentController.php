<?php

declare(strict_types=1);

namespace App\Http\Controller\Vet;

use App\Domain\AppointmentStatus;
use App\Service\ServiceFactory;
use Twig\Environment;

final class AppointmentController
{
    use ResolvesVet;

    public function __construct(
        private readonly Environment $twig,
        private readonly ServiceFactory $services = new ServiceFactory(),
    ) {
    }

    public function index(): string
    {
        $vet = $this->currentVet();
        $board = $this->services->vetAppointmentBoardService()->forVet($vet->id);

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
     */
    public function markNoShow(array $vars): string
    {
        return $this->transition($vars, [AppointmentStatus::Confirmed], AppointmentStatus::NoShow);
    }

    /**
     * @param array<string, string> $vars
     * @param list<AppointmentStatus> $allowedFrom
     */
    private function transition(array $vars, array $allowedFrom, AppointmentStatus $to): string
    {
        $vet = $this->currentVet();
        $this->services->appointmentTransitionService()
            ->transition((int) $vars['id'], $vet->id, $allowedFrom, $to);

        header('Location: /vet/appointments');

        return '';
    }
}

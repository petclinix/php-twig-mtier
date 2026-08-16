<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\Session;
use App\Repository\UserRepository;
use Twig\Environment;

final class DashboardController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function index(array $vars): string
    {
        $userId = Session::userId();
        $user = $userId !== null ? (new UserRepository())->findById($userId) : null;

        return $this->twig->render('dashboard.html.twig', ['user' => $user]);
    }
}

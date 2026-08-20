<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Http\Session;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use App\Service\AdminService;
use Twig\Environment;

final class UserController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function index(): string
    {
        $users = (new UserRepository())->findAll();

        return $this->twig->render('admin/users/index.html.twig', ['users' => $users]);
    }

    /**
     * @param array<string, string> $vars
     */
    public function activate(array $vars): string
    {
        return $this->setActive($vars, true);
    }

    /**
     * @param array<string, string> $vars
     */
    public function deactivate(array $vars): string
    {
        return $this->setActive($vars, false);
    }

    /**
     * @param array<string, string> $vars
     */
    private function setActive(array $vars, bool $active): string
    {
        $actorId = Session::userId();
        $targetId = (int) $vars['id'];

        if ($actorId !== null && $actorId !== $targetId) {
            (new AdminService(new UserRepository(), new ActivityLogRepository()))
                ->setUserActive($actorId, $targetId, $active);
        }

        header('Location: /admin/users');

        return '';
    }
}

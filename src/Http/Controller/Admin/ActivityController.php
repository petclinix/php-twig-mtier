<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use App\Service\ActivityLogService;
use Twig\Environment;

final class ActivityController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function index(): string
    {
        $board = (new ActivityLogService(new ActivityLogRepository(), new UserRepository()))
            ->recentWithUsers();

        return $this->twig->render('admin/activity/index.html.twig', $board);
    }
}

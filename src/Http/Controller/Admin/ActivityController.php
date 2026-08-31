<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Service\ServiceFactory;
use Twig\Environment;

final class ActivityController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ServiceFactory $services = new ServiceFactory(),
    ) {}

    public function index(): string
    {
        $board = $this->services->activityLogService()->recentWithUsers();

        return $this->twig->render('admin/activity/index.html.twig', $board);
    }
}

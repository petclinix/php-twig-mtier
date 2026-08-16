<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Repository\StatsRepository;
use Twig\Environment;

final class StatsController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function index(array $vars): string
    {
        $stats = (new StatsRepository())->summary();

        return $this->twig->render('admin/stats.html.twig', ['stats' => $stats]);
    }
}

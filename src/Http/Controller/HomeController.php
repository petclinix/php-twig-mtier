<?php

declare(strict_types=1);

namespace App\Http\Controller;

use Twig\Environment;

final class HomeController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function index(array $vars): string
    {
        return $this->twig->render('home.html.twig');
    }
}

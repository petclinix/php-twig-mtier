<?php

declare(strict_types=1);

namespace App\Http\Controller;

use Twig\Environment;

final class HomeController
{
    public function __construct(private readonly Environment $twig) {}

    public function index(): string
    {
        return $this->twig->render('home.html.twig');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Support\Router;

use Twig\Environment;

final class FakeController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    /**
     * @param array<string, string> $vars
     */
    public function respond(array $vars): string
    {
        return 'controller-ran:' . ($vars['id'] ?? '');
    }
}

<?php

declare(strict_types=1);

namespace App\Http;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class TwigFactory
{
    public static function create(): Environment
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
            ['cache' => false],
        );
        $twig->addFunction(new TwigFunction('csrf_token', static fn(): string => Csrf::token()));

        return $twig;
    }
}

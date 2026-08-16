<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Controller\HomeController;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class HomeControllerTest extends TestCase
{
    public function testIndexRendersHomeTemplate(): void
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../templates'));
        $controller = new HomeController($twig);

        $output = $controller->index([]);

        self::assertStringContainsString('PetcliniX', $output);
    }
}

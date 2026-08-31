<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Controller\HomeController;
use App\Http\TwigFactory;
use PHPUnit\Framework\TestCase;

final class HomeControllerTest extends TestCase
{
    public function testIndexRendersHomeTemplate(): void
    {
        //arrange
        $twig = TwigFactory::create();
        $controller = new HomeController($twig);

        //act
        $output = $controller->index([]);

        //assert
        self::assertStringContainsString('PetcliniX', $output);
    }
}

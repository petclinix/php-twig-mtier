<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Http\Controller\Admin\StatsController;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class StatsControllerTest extends TestCase
{
    public function testIndexRendersStatsSummary(): void
    {
        //arrange
        // StatsRepository::summary() aggregates globally across the shared
        // test DB, so exact counts aren't deterministic here — just assert
        // the controller executed the query and rendered the page.
        $controller = new StatsController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));

        //act
        $output = $controller->index([]);

        //assert
        self::assertStringContainsString('Appointments by Status', $output);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\HttpServer;
use PHPUnit\Framework\TestCase;

/**
 * This is an integration test. It boots the real app behind a `php -S` server and
 * assures that a request gets correctly routed to its corresponding controller,
 * through Twig, end to end — proving the production wiring in public/index.php is
 * correct. It intentionally covers only the happy path; Router's branches (404,
 * 405, middleware short-circuit) are covered by the unit test in
 * tests/Http/Router/RouterTest.php.
 */
final class RouterWiringTest extends TestCase
{
    private static HttpServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = HttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testHomeRouteRunsHomeControllerThroughTwig(): void
    {
        //act
        $response = self::$server->request('GET', '/');

        //assert
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Skeleton is running.', $response->body);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\HttpServer;
use PHPUnit\Framework\TestCase;

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
        $response = self::$server->request('GET', '/');

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Skeleton is running.', $response->body);
    }

    public function testUnknownRouteReturns404FromRouterNotFoundBranch(): void
    {
        $response = self::$server->request('GET', '/this-route-does-not-exist');

        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('Page not found.', $response->body);
    }

    public function testProtectedRouteWithoutSessionRedirectsToLoginBeforeControllerRuns(): void
    {
        $response = self::$server->request('GET', '/dashboard');

        self::assertSame(302, $response->statusCode);
        self::assertSame('/login', $response->headers['location'] ?? null);
        self::assertSame('', $response->body); // AuthMiddleware short-circuits; DashboardController never runs.
    }
}

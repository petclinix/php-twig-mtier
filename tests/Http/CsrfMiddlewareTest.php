<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\Support\HttpServer;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
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

    private function fetchLoginPageTokenAndCookie(): array
    {
        $page = self::$server->request('GET', '/login');
        self::assertSame(200, $page->statusCode);

        $cookie = $page->sessionCookie();
        self::assertNotNull($cookie, 'Expected a PHPSESSID cookie from GET /login.');

        preg_match('/name="_token" value="([^"]+)"/', $page->body, $matches);
        self::assertArrayHasKey(1, $matches, 'Expected a hidden _token field on the login form.');

        return [$cookie, $matches[1]];
    }

    public function testPostWithoutTokenIsRejected(): void
    {
        //arrange
        [$cookie] = $this->fetchLoginPageTokenAndCookie();

        //act
        $response = self::$server->request(
            'POST',
            '/login',
            ['Cookie' => $cookie],
            http_build_query(['email' => 'nobody@example.test', 'password' => 'irrelevant']),
        );

        //assert
        self::assertSame(403, $response->statusCode);
        self::assertSame('', $response->body);
    }

    public function testPostWithAMismatchedTokenIsRejected(): void
    {
        //arrange
        [$cookie] = $this->fetchLoginPageTokenAndCookie();

        //act
        $response = self::$server->request(
            'POST',
            '/login',
            ['Cookie' => $cookie],
            http_build_query(['email' => 'nobody@example.test', 'password' => 'irrelevant', '_token' => 'forged']),
        );

        //assert
        self::assertSame(403, $response->statusCode);
        self::assertSame('', $response->body);
    }

    public function testPostWithTheCorrectTokenPassesTheCsrfCheck(): void
    {
        //arrange
        [$cookie, $token] = $this->fetchLoginPageTokenAndCookie();

        //act
        $response = self::$server->request(
            'POST',
            '/login',
            ['Cookie' => $cookie],
            http_build_query(['email' => 'nobody@example.test', 'password' => 'irrelevant', '_token' => $token]),
        );

        //assert
        // Invalid credentials, but the CSRF check passed: the request reached
        // AuthController and was re-rendered with a validation error, not a 403.
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('<form method="post" action="/login">', $response->body);
    }
}

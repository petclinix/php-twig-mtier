<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Role;
use App\Infrastructure\Database;
use App\Repository\ActivityLogRepository;
use App\Repository\OwnerRepository;
use App\Repository\UserRepository;
use App\Repository\VetRepository;
use App\Service\AuthService;
use App\Tests\Support\HttpServer;
use PHPUnit\Framework\TestCase;

final class AuthenticatedRoutingTest extends TestCase
{
    private const PASSWORD = 'correct-horse';

    private static HttpServer $server;
    private string $ownerEmail;
    private string $vetEmail;
    private string $adminEmail;

    public static function setUpBeforeClass(): void
    {
        self::$server = HttpServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "owner-router-{$suffix}@example.test";
        $this->vetEmail = "vet-router-{$suffix}@example.test";
        $this->adminEmail = "admin-router-{$suffix}@example.test";

        $auth = new AuthService(new UserRepository(), new OwnerRepository(), new VetRepository(), new ActivityLogRepository());
        $auth->register($this->ownerEmail, self::PASSWORD, Role::Owner, [
            'first_name' => 'Router', 'last_name' => 'Owner', 'phone' => '555-0100', 'address' => '1 Main St',
        ]);
        $auth->register($this->vetEmail, self::PASSWORD, Role::Vet, [
            'first_name' => 'Router', 'last_name' => 'Vet', 'specialty' => 'Surgery',
        ]);

        // Admins are seeded, not self-registered — insert directly, matching AdminPortalTest.
        Database::connection()
            ->prepare('INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, :role)')
            ->execute(['email' => $this->adminEmail, 'hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT), 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet, :admin)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail, 'admin' => $this->adminEmail]);
    }

    private function loginAs(string $email): string
    {
        $response = self::$server->request(
            'POST',
            '/login',
            [],
            http_build_query(['email' => $email, 'password' => self::PASSWORD]),
        );

        $cookie = $response->sessionCookie();
        self::assertNotNull($cookie, "Expected a PHPSESSID cookie from logging in as {$email}.");

        return $cookie;
    }

    public function testLoginThenDashboardRunsFullRouterMiddlewareControllerChain(): void
    {
        //act
        $loginResponse = self::$server->request(
            'POST',
            '/login',
            [],
            http_build_query(['email' => $this->ownerEmail, 'password' => self::PASSWORD]),
        );

        //assert
        self::assertSame(302, $loginResponse->statusCode);
        self::assertSame('/dashboard', $loginResponse->headers['location'] ?? null);

        $cookie = $loginResponse->sessionCookie();
        self::assertNotNull($cookie);

        //act
        $dashboard = self::$server->request('GET', '/dashboard', ['Cookie' => $cookie]);

        //assert
        self::assertSame(200, $dashboard->statusCode);
        self::assertStringContainsString("Logged in as {$this->ownerEmail}", $dashboard->body);
    }

    public function testOwnerCanReachOwnerPetsRoute(): void
    {
        //arrange
        $cookie = $this->loginAs($this->ownerEmail);

        //act
        $response = self::$server->request('GET', '/owner/pets', ['Cookie' => $cookie]);

        //assert
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('My Pets', $response->body);
    }

    public function testNonOwnerIsRedirectedAwayFromOwnerRoute(): void
    {
        //arrange
        $cookie = $this->loginAs($this->vetEmail);

        //act
        $response = self::$server->request('GET', '/owner/pets', ['Cookie' => $cookie]);

        //assert
        self::assertSame(302, $response->statusCode);
        self::assertSame('/dashboard', $response->headers['location'] ?? null);
        self::assertSame('', $response->body); // OwnerMiddleware short-circuits; PetController never runs.
    }

    public function testAdminCanReachAdminStatsRoute(): void
    {
        //arrange
        $cookie = $this->loginAs($this->adminEmail);

        //act
        $response = self::$server->request('GET', '/admin', ['Cookie' => $cookie]);

        //assert
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Stats', $response->body);
    }

    public function testNonAdminIsRedirectedAwayFromAdminRoute(): void
    {
        //arrange
        $cookie = $this->loginAs($this->ownerEmail);

        //act
        $response = self::$server->request('GET', '/admin', ['Cookie' => $cookie]);

        //assert
        self::assertSame(302, $response->statusCode);
        self::assertSame('/dashboard', $response->headers['location'] ?? null);
        self::assertSame('', $response->body); // AdminMiddleware short-circuits; StatsController never runs.
    }

    public function testVetCanReachVetAvailabilityRoute(): void
    {
        //arrange
        $cookie = $this->loginAs($this->vetEmail);

        //act
        $response = self::$server->request('GET', '/vet/availability', ['Cookie' => $cookie]);

        //assert
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('My Weekly Availability', $response->body);
    }

    public function testNonVetIsRedirectedAwayFromVetRoute(): void
    {
        //arrange
        $cookie = $this->loginAs($this->ownerEmail);

        //act
        $response = self::$server->request('GET', '/vet/availability', ['Cookie' => $cookie]);

        //assert
        self::assertSame(302, $response->statusCode);
        self::assertSame('/dashboard', $response->headers['location'] ?? null);
        self::assertSame('', $response->body); // VetMiddleware short-circuits; VetAvailabilityController never runs.
    }
}

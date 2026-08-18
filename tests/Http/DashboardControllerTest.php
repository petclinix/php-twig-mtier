<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Controller\DashboardController;
use App\Tests\Support\CreatesTestUsers;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class DashboardControllerTest extends TestCase
{
    use CreatesTestUsers;

    private DashboardController $controller;
    private string $email;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->email = sprintf('dashboard-ctrl-%s@example.test', bin2hex(random_bytes(6)));
        $this->controller = new DashboardController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));
    }

    protected function tearDown(): void
    {
        \App\Infrastructure\Database::connection()
            ->prepare('DELETE FROM users WHERE email = :email')
            ->execute(['email' => $this->email]);
        $_SESSION = [];
    }

    public function testIndexShowsLoggedInUserWhenSessionResolvesToAUser(): void
    {
        $owner = $this->registerOwner($this->email);
        $this->loginAs($owner->userId, 'owner');

        $output = $this->controller->index([]);

        self::assertStringContainsString("Logged in as {$this->email}", $output);
    }

    public function testIndexShowsFallbackWhenSessionUserDoesNotResolve(): void
    {
        $this->loginAs(999_999_999, 'owner');

        $output = $this->controller->index([]);

        self::assertStringContainsString('Session user not found.', $output);
    }
}

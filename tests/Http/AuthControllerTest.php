<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Controller\AuthController;
use App\Http\Session;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Tests\Support\HeaderSpy;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AuthControllerTest extends TestCase
{
    private AuthController $controller;
    private string $email;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
        }
        Session::start();
        HeaderSpy::reset();
        $_POST = [];
        $this->email = sprintf('auth-ctrl-%s@example.test', bin2hex(random_bytes(6)));
        $this->controller = new AuthController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));
    }

    protected function tearDown(): void
    {
        Database::connection()->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $this->email]);
        $_POST = [];
    }

    public function testShowRegisterRendersForm(): void
    {
        self::assertStringContainsString('<h2>Register</h2>', $this->controller->showRegister([]));
    }

    public function testRegisterWithMismatchedPasswordsShowsValidationError(): void
    {
        $_POST = [
            'email' => $this->email, 'password' => 'correct-horse', 'password_confirmation' => 'wrong',
            'role' => 'owner', 'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '555', 'address' => 'Main St',
        ];

        $output = $this->controller->register([]);

        self::assertStringContainsString('Passwords do not match.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testRegisterWithValidOwnerDataLogsInAndRedirects(): void
    {
        $_POST = [
            'email' => $this->email, 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse',
            'role' => 'owner', 'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '555-0100', 'address' => '1 Main St',
        ];

        $output = $this->controller->register([]);

        self::assertSame('', $output);
        self::assertSame('/dashboard', HeaderSpy::location());
        self::assertTrue(Session::isAuthenticated());
        self::assertNotNull((new UserRepository())->findByEmail($this->email));
    }

    public function testRegisterWithDuplicateEmailShowsError(): void
    {
        $_POST = [
            'email' => $this->email, 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse',
            'role' => 'vet', 'first_name' => 'A', 'last_name' => 'B', 'specialty' => 'Surgery',
        ];
        $this->controller->register([]); // first registration succeeds
        HeaderSpy::reset();

        $output = $this->controller->register([]); // second attempt, same email

        self::assertStringContainsString('An account with that email already exists.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testShowLoginRendersForm(): void
    {
        self::assertStringContainsString('<h2>Log in</h2>', $this->controller->showLogin([]));
    }

    public function testLoginWithInvalidCredentialsShowsError(): void
    {
        $_POST = ['email' => $this->email, 'password' => 'nope'];

        $output = $this->controller->login([]);

        self::assertStringContainsString('Invalid email or password.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testLogoutClearsSessionAndRedirectsHome(): void
    {
        Session::login(1, 'owner');

        $output = $this->controller->logout([]);

        self::assertSame('', $output);
        self::assertSame('/', HeaderSpy::location());
        self::assertFalse(Session::isAuthenticated());
    }
}

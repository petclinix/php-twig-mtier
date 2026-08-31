<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\Controller\AuthController;
use App\Http\Session;
use App\Http\TwigFactory;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Tests\Support\HeaderSpy;
use PHPUnit\Framework\TestCase;

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
        $this->controller = new AuthController(TwigFactory::create());
    }

    protected function tearDown(): void
    {
        Database::connection()->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $this->email]);
        $_POST = [];
    }

    public function testShowRegisterRendersForm(): void
    {
        //act
        $output = $this->controller->showRegister([]);

        //assert
        self::assertStringContainsString('<h2>Register</h2>', $output);
    }

    public function testRegisterWithMismatchedPasswordsShowsValidationError(): void
    {
        //arrange
        $_POST = [
            'email' => $this->email, 'password' => 'correct-horse', 'password_confirmation' => 'wrong',
            'role' => 'owner', 'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '555', 'address' => 'Main St',
        ];

        //act
        $output = $this->controller->register([]);

        //assert
        self::assertStringContainsString('Passwords do not match.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testRegisterWithValidOwnerDataLogsInAndRedirects(): void
    {
        //arrange
        $_POST = [
            'email' => $this->email, 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse',
            'role' => 'owner', 'first_name' => 'Jane', 'last_name' => 'Doe', 'phone' => '555-0100', 'address' => '1 Main St',
        ];

        //act
        $output = $this->controller->register([]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/dashboard', HeaderSpy::location());
        self::assertTrue(Session::isAuthenticated());
        self::assertNotNull((new UserRepository())->findByEmail($this->email));
    }

    public function testRegisterWithDuplicateEmailShowsError(): void
    {
        //arrange
        $_POST = [
            'email' => $this->email, 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse',
            'role' => 'vet', 'first_name' => 'A', 'last_name' => 'B', 'specialty' => 'Surgery',
        ];
        $this->controller->register([]); // first registration succeeds
        HeaderSpy::reset();

        //act
        $output = $this->controller->register([]); // second attempt, same email

        //assert
        self::assertStringContainsString('An account with that email already exists.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testShowLoginRendersForm(): void
    {
        //act
        $output = $this->controller->showLogin([]);

        //assert
        self::assertStringContainsString('<h2>Log in</h2>', $output);
    }

    public function testLoginWithInvalidCredentialsShowsError(): void
    {
        //arrange
        $_POST = ['email' => $this->email, 'password' => 'nope'];

        //act
        $output = $this->controller->login([]);

        //assert
        self::assertStringContainsString('Invalid email or password.', $output);
        self::assertSame([], HeaderSpy::$headers);
    }

    public function testLogoutClearsSessionAndRedirectsHome(): void
    {
        //arrange
        Session::login(1, 'owner');

        //act
        $output = $this->controller->logout([]);

        //assert
        self::assertSame('', $output);
        self::assertSame('/', HeaderSpy::location());
        self::assertFalse(Session::isAuthenticated());
    }
}

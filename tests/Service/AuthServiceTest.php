<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Role;
use App\Infrastructure\Database;
use App\Repository\OwnerRepository;
use App\Repository\UserRepository;
use App\Repository\VetRepository;
use App\Service\AuthService;
use App\Service\EmailAlreadyRegisteredException;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private AuthService $auth;
    private string $email;

    protected function setUp(): void
    {
        $this->auth = new AuthService(new UserRepository(), new OwnerRepository(), new VetRepository());
        $this->email = sprintf('test-%s@example.test', bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email = :email')
            ->execute(['email' => $this->email]);
    }

    public function testRegisterOwnerAndAttemptLogin(): void
    {
        $user = $this->auth->register($this->email, 'correct-horse', Role::Owner, [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'address' => '1 Main St',
        ]);

        self::assertSame(Role::Owner, $user->role);

        $authenticated = $this->auth->attempt($this->email, 'correct-horse');
        self::assertNotNull($authenticated);
        self::assertSame($user->id, $authenticated->id);

        self::assertNull($this->auth->attempt($this->email, 'wrong-password'));
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $this->auth->register($this->email, 'correct-horse', Role::Vet, [
            'first_name' => 'John',
            'last_name' => 'Smith',
            'specialty' => 'Surgery',
        ]);

        $this->expectException(EmailAlreadyRegisteredException::class);

        $this->auth->register($this->email, 'another-password', Role::Vet, [
            'first_name' => 'John',
            'last_name' => 'Smith',
            'specialty' => 'Surgery',
        ]);
    }
}

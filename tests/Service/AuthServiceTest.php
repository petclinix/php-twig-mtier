<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Role;
use App\Infrastructure\Database;
use App\Repository\ActivityLogRepository;
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
        $this->auth = new AuthService(
            new UserRepository(),
            new OwnerRepository(),
            new VetRepository(),
            new ActivityLogRepository(),
        );
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
        //act
        $user = $this->auth->register($this->email, 'correct-horse', Role::Owner, [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'address' => '1 Main St',
        ]);

        //assert
        self::assertSame(Role::Owner, $user->role);

        //act
        $authenticated = $this->auth->attempt($this->email, 'correct-horse');

        //assert
        self::assertNotNull($authenticated);
        self::assertSame($user->id, $authenticated->id);

        //act + assert
        self::assertNull($this->auth->attempt($this->email, 'wrong-password'));
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        //arrange
        $this->auth->register($this->email, 'correct-horse', Role::Vet, [
            'first_name' => 'John',
            'last_name' => 'Smith',
            'specialty' => 'Surgery',
        ]);
        $this->expectException(EmailAlreadyRegisteredException::class);

        //act + assert
        $this->auth->register($this->email, 'another-password', Role::Vet, [
            'first_name' => 'John',
            'last_name' => 'Smith',
            'specialty' => 'Surgery',
        ]);
    }
}

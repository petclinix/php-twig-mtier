<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Owner;
use App\Domain\Role;
use App\Domain\User;
use App\Domain\Vet;
use App\Http\Session;
use App\Infrastructure\Database;
use App\Repository\ActivityLogRepository;
use App\Repository\OwnerRepository;
use App\Repository\UserRepository;
use App\Repository\VetRepository;
use App\Service\AuthService;

trait CreatesTestUsers
{
    private function registerOwner(string $email, string $password = 'correct-horse'): Owner
    {
        $user = $this->authService()->register($email, $password, Role::Owner, [
            'first_name' => 'Test', 'last_name' => 'Owner', 'phone' => '555-0100', 'address' => '1 Main St',
        ]);

        return (new OwnerRepository())->findByUserId($user->id);
    }

    private function registerVet(string $email, string $password = 'correct-horse'): Vet
    {
        $user = $this->authService()->register($email, $password, Role::Vet, [
            'first_name' => 'Test', 'last_name' => 'Vet', 'specialty' => 'Surgery',
        ]);

        return (new VetRepository())->findByUserId($user->id);
    }

    private function createAdminUser(string $email, string $password = 'correct-horse'): User
    {
        Database::connection()
            ->prepare('INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, :role)')
            ->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_BCRYPT), 'role' => 'admin']);

        return (new UserRepository())->findByEmail($email);
    }

    private function loginAs(int $userId, string $role): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
        }
        Session::start();
        Session::login($userId, $role);
    }

    private function authService(): AuthService
    {
        return new AuthService(new UserRepository(), new OwnerRepository(), new VetRepository(), new ActivityLogRepository());
    }
}

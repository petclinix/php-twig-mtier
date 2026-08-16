<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Role;
use App\Domain\User;
use App\Infrastructure\Database;
use App\Repository\OwnerRepository;
use App\Repository\UserRepository;
use App\Repository\VetRepository;
use Throwable;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly OwnerRepository $owners,
        private readonly VetRepository $vets,
    ) {
    }

    /**
     * @param array<string, string> $profile
     */
    public function register(string $email, string $password, Role $role, array $profile): User
    {
        if ($this->users->findByEmail($email) !== null) {
            throw new EmailAlreadyRegisteredException($email);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $user = $this->users->create($email, password_hash($password, PASSWORD_BCRYPT), $role);

            if ($role === Role::Owner) {
                $this->owners->createProfile(
                    $user->id,
                    $profile['first_name'],
                    $profile['last_name'],
                    $profile['phone'],
                    $profile['address'],
                );
            } else {
                $this->vets->createProfile(
                    $user->id,
                    $profile['first_name'],
                    $profile['last_name'],
                    $profile['specialty'],
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $user;
    }

    public function attempt(string $email, string $password): ?User
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !password_verify($password, $user->passwordHash)) {
            return null;
        }

        return $user;
    }
}

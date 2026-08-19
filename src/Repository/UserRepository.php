<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Role;
use App\Domain\User;
use App\Repository\Exception\UserPersistenceException;
use DateTimeImmutable;

final class UserRepository extends AbstractRepository
{
    public function findByEmail(string $email): ?User
    {
        return $this->fetchOne(
            'SELECT id, email, password_hash, role, is_active, created_at FROM users WHERE email = :email',
            ['email' => $email],
            $this->hydrate(...),
        );
    }

    public function findById(int $id): ?User
    {
        return $this->fetchOne(
            'SELECT id, email, password_hash, role, is_active, created_at FROM users WHERE id = :id',
            ['id' => $id],
            $this->hydrate(...),
        );
    }

    /**
     * @return list<User>
     */
    public function findAll(): array
    {
        return $this->fetchAll(
            'SELECT id, email, password_hash, role, is_active, created_at FROM users ORDER BY created_at DESC',
            [],
            $this->hydrate(...),
        );
    }

    public function create(string $email, string $passwordHash, Role $role): User
    {
        $this->execute(
            'INSERT INTO users (email, password_hash, role) VALUES (:email, :password_hash, :role)',
            [
                'email' => $email,
                'password_hash' => $passwordHash,
                'role' => $role->value,
            ],
        );

        return $this->findById($this->lastInsertId()) ?? throw UserPersistenceException::failedToLoadAfterInsert();
    }

    public function setActive(int $id, bool $active): void
    {
        $this->execute(
            'UPDATE users SET is_active = :is_active WHERE id = :id',
            ['is_active' => $active ? 1 : 0, 'id' => $id],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        return new User(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['password_hash'],
            Role::from((string) $row['role']),
            (bool) $row['is_active'],
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}

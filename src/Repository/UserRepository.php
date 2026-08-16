<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Role;
use App\Domain\User;
use App\Infrastructure\Database;
use DateTimeImmutable;
use PDO;

final class UserRepository
{
    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT id, email, password_hash, role, created_at FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT id, email, password_hash, role, created_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(string $email, string $passwordHash, Role $role): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, password_hash, role) VALUES (:email, :password_hash, :role)'
        );
        $stmt->execute([
            'email' => $email,
            'password_hash' => $passwordHash,
            'role' => $role->value,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        return $this->findById($id) ?? throw new \RuntimeException('Failed to load user after insert.');
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
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}

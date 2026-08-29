<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        return self::$connection ??= self::newConnection();
    }

    /**
     * Builds a fresh, independent connection rather than reusing the memoized
     * singleton — needed when code genuinely requires two separate DB sessions
     * (e.g. proving InnoDB serializes concurrent writers in a test).
     */
    public static function newConnection(): PDO
    {
        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'petclinix';
        $user = getenv('DB_USER') ?: 'petclinix';
        $pass = getenv('DB_PASSWORD') ?: 'petclinix';

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function runInTransaction(callable $work): mixed
    {
        $pdo = self::connection();
        $pdo->beginTransaction();

        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

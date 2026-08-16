<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\ActivityLogEntry;
use App\Infrastructure\Database;
use DateTimeImmutable;
use PDO;

final class ActivityLogRepository
{
    private readonly PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function record(?int $userId, string $action, ?array $context = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO activity_log (user_id, action, context) VALUES (:user_id, :action, :context)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action' => $action,
            'context' => $context !== null ? json_encode($context, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    /**
     * @return list<ActivityLogEntry>
     */
    public function findRecent(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, action, context, created_at FROM activity_log ORDER BY created_at DESC, id DESC LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ActivityLogEntry
    {
        return new ActivityLogEntry(
            (int) $row['id'],
            $row['user_id'] !== null ? (int) $row['user_id'] : null,
            (string) $row['action'],
            $row['context'] !== null ? json_decode((string) $row['context'], true, 512, JSON_THROW_ON_ERROR) : null,
            new DateTimeImmutable((string) $row['created_at']),
        );
    }
}

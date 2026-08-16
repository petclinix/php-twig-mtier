<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use Throwable;

final class AdminService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ActivityLogRepository $activityLog,
    ) {
    }

    public function setUserActive(int $actorUserId, int $targetUserId, bool $active): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $this->users->setActive($targetUserId, $active);
            $this->activityLog->record(
                $actorUserId,
                $active ? 'user_activated' : 'user_deactivated',
                ['target_user_id' => $targetUserId],
            );

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\Database;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;

final class AdminService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ActivityLogRepository $activityLog,
    ) {
    }

    public function setUserActive(int $actorUserId, int $targetUserId, bool $active): void
    {
        Database::runInTransaction(function () use ($actorUserId, $targetUserId, $active): void {
            $this->users->setActive($targetUserId, $active);
            $this->activityLog->record(
                $actorUserId,
                $active ? 'user_activated' : 'user_deactivated',
                ['target_user_id' => $targetUserId],
            );
        });
    }
}

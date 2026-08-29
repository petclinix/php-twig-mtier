<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\ActivityLogEntry;
use App\Domain\User;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;

final class ActivityLogService
{
    public function __construct(
        private readonly ActivityLogRepository $activityLog,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return array{entries: list<ActivityLogEntry>, usersById: array<int, User>}
     */
    public function recentWithUsers(int $limit = 100): array
    {
        $entries = $this->activityLog->findRecent($limit);

        $usersById = [];
        foreach ($entries as $entry) {
            if ($entry->userId === null || isset($usersById[$entry->userId])) {
                continue;
            }

            $user = $this->users->findById($entry->userId);
            if ($user !== null) {
                $usersById[$user->id] = $user;
            }
        }

        return ['entries' => $entries, 'usersById' => $usersById];
    }
}

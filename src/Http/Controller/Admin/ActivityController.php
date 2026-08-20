<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use Twig\Environment;

final class ActivityController
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function index(): string
    {
        $entries = (new ActivityLogRepository())->findRecent(100);

        $userRepository = new UserRepository();
        $usersById = [];
        foreach ($entries as $entry) {
            if ($entry->userId === null || isset($usersById[$entry->userId])) {
                continue;
            }

            $user = $userRepository->findById($entry->userId);
            if ($user !== null) {
                $usersById[$user->id] = $user;
            }
        }

        return $this->twig->render('admin/activity/index.html.twig', [
            'entries' => $entries,
            'usersById' => $usersById,
        ]);
    }
}

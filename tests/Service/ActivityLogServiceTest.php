<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Domain\Owner;
use App\Infrastructure\Database;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use App\Service\ActivityLogService;
use App\Tests\Support\CreatesTestUsers;
use PHPUnit\Framework\TestCase;

final class ActivityLogServiceTest extends TestCase
{
    use CreatesTestUsers;

    private ActivityLogService $service;
    private ActivityLogRepository $activityLog;
    private string $ownerEmail;
    private Owner $owner;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "activitylog-owner-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);

        $this->activityLog = new ActivityLogRepository();
        $this->service = new ActivityLogService($this->activityLog, new UserRepository());
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email = :owner')
            ->execute(['owner' => $this->ownerEmail]);
    }

    public function testRecentWithUsersResolvesUserForEntry(): void
    {
        //arrange
        $this->activityLog->record($this->owner->userId, 'test_action');

        //act
        $board = $this->service->recentWithUsers();

        //assert
        self::assertArrayHasKey($this->owner->userId, $board['usersById']);
        self::assertSame($this->owner->userId, $board['usersById'][$this->owner->userId]->id);
        self::assertNotEmpty($board['entries']);
    }

    public function testRecentWithUsersDedupesRepeatedUser(): void
    {
        //arrange
        $this->activityLog->record($this->owner->userId, 'test_action_one');
        $this->activityLog->record($this->owner->userId, 'test_action_two');

        //act
        $board = $this->service->recentWithUsers();

        //assert
        self::assertCount(1, array_filter(
            $board['usersById'],
            fn (int $id): bool => $id === $this->owner->userId,
            ARRAY_FILTER_USE_KEY,
        ));
    }

    public function testRecentWithUsersSkipsEntriesWithNullUserId(): void
    {
        //arrange
        $this->activityLog->record(null, 'system_action');

        //act
        $board = $this->service->recentWithUsers();

        //assert
        self::assertNotContains(null, array_keys($board['usersById']));
    }
}

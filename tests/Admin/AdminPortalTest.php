<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Domain\Role;
use App\Infrastructure\Database;
use App\Repository\ActivityLogRepository;
use App\Repository\OwnerRepository;
use App\Repository\StatsRepository;
use App\Repository\UserRepository;
use App\Repository\VetRepository;
use App\Service\AdminService;
use App\Service\AuthService;
use PHPUnit\Framework\TestCase;

final class AdminPortalTest extends TestCase
{
    private string $adminEmail;
    private string $ownerEmail;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->adminEmail = "admin-{$suffix}@example.test";
        $this->ownerEmail = "owner-{$suffix}@example.test";
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:admin, :owner)')
            ->execute(['admin' => $this->adminEmail, 'owner' => $this->ownerEmail]);
    }

    public function testAdminCanDeactivateAndReactivateAUserWithActivityLogged(): void
    {
        $activityLog = new ActivityLogRepository();
        $auth = new AuthService(new UserRepository(), new OwnerRepository(), new VetRepository(), $activityLog);
        $users = new UserRepository();

        // Admins are seeded, not self-registered; insert directly like the seed data does.
        Database::connection()
            ->prepare('INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, :role)')
            ->execute(['email' => $this->adminEmail, 'hash' => password_hash('correct-horse', PASSWORD_BCRYPT), 'role' => 'admin']);
        $admin = $users->findByEmail($this->adminEmail);
        self::assertNotNull($admin);

        $ownerUser = $auth->register($this->ownerEmail, 'correct-horse', Role::Owner, [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'address' => '1 Main St',
        ]);
        self::assertTrue($ownerUser->isActive);

        $adminService = new AdminService($users, $activityLog);
        $adminService->setUserActive($admin->id, $ownerUser->id, false);

        $deactivated = $users->findById($ownerUser->id);
        self::assertNotNull($deactivated);
        self::assertFalse($deactivated->isActive);

        // attempt() only verifies credentials; the active check happens at the controller.
        $stillAuthenticates = $auth->attempt($this->ownerEmail, 'correct-horse');
        self::assertNotNull($stillAuthenticates);
        self::assertFalse($stillAuthenticates->isActive);

        $adminService->setUserActive($admin->id, $ownerUser->id, true);
        $reactivated = $users->findById($ownerUser->id);
        self::assertNotNull($reactivated);
        self::assertTrue($reactivated->isActive);

        $entries = $activityLog->findRecent(50);
        $actions = array_map(static fn ($entry) => $entry->action, $entries);
        self::assertContains('user_deactivated', $actions);
        self::assertContains('user_activated', $actions);
        self::assertContains('user_registered', $actions);

        $stats = (new StatsRepository())->summary();
        self::assertGreaterThanOrEqual(1, $stats->ownerCount);
    }
}

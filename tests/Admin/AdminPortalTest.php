<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Domain\Role;
use App\Domain\User;
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
    private ActivityLogRepository $activityLog;
    private UserRepository $users;
    private AdminService $adminService;
    private User $admin;
    private User $ownerUser;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->adminEmail = "admin-{$suffix}@example.test";
        $this->ownerEmail = "owner-{$suffix}@example.test";

        $this->activityLog = new ActivityLogRepository();
        $this->users = new UserRepository();
        $auth = new AuthService(new UserRepository(), new OwnerRepository(), new VetRepository(), $this->activityLog);
        $this->adminService = new AdminService($this->users, $this->activityLog);

        // Admins are seeded, not self-registered; insert directly like the seed data does.
        Database::connection()
            ->prepare('INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, :role)')
            ->execute(['email' => $this->adminEmail, 'hash' => password_hash('correct-horse', PASSWORD_BCRYPT), 'role' => 'admin']);
        $this->admin = $this->users->findByEmail($this->adminEmail);

        $this->ownerUser = $auth->register($this->ownerEmail, 'correct-horse', Role::Owner, [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone' => '555-0100',
            'address' => '1 Main St',
        ]);
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:admin, :owner)')
            ->execute(['admin' => $this->adminEmail, 'owner' => $this->ownerEmail]);
    }

    public function testAdminCanDeactivateAUser(): void
    {
        //act
        $this->adminService->setUserActive($this->admin->id, $this->ownerUser->id, false);

        //assert
        $deactivated = $this->users->findById($this->ownerUser->id);
        self::assertFalse($deactivated->isActive);

        // attempt() only verifies credentials; the active check happens at the controller.
        $auth = new AuthService(new UserRepository(), new OwnerRepository(), new VetRepository(), $this->activityLog);
        $stillAuthenticates = $auth->attempt($this->ownerEmail, 'correct-horse');
        self::assertNotNull($stillAuthenticates);
        self::assertFalse($stillAuthenticates->isActive);
    }

    public function testAdminCanReactivateADeactivatedUser(): void
    {
        //arrange
        $this->adminService->setUserActive($this->admin->id, $this->ownerUser->id, false);

        //act
        $this->adminService->setUserActive($this->admin->id, $this->ownerUser->id, true);

        //assert
        $reactivated = $this->users->findById($this->ownerUser->id);
        self::assertTrue($reactivated->isActive);
    }

    public function testDeactivateAndReactivateAreLoggedToActivityLog(): void
    {
        //arrange
        $this->adminService->setUserActive($this->admin->id, $this->ownerUser->id, false);
        $this->adminService->setUserActive($this->admin->id, $this->ownerUser->id, true);

        //act
        $entries = $this->activityLog->findRecent(50);

        //assert
        $actions = array_map(static fn ($entry) => $entry->action, $entries);
        self::assertContains('user_deactivated', $actions);
        self::assertContains('user_activated', $actions);
        self::assertContains('user_registered', $actions);
    }

    public function testStatsCountAtLeastOneRegisteredOwner(): void
    {
        //act
        $stats = (new StatsRepository())->summary();

        //assert
        self::assertGreaterThanOrEqual(1, $stats->ownerCount);
    }
}

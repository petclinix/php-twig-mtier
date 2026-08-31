<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Http\Controller\Admin\ActivityController;
use App\Http\TwigFactory;
use App\Infrastructure\Database;
use App\Tests\Support\CreatesTestUsers;
use PHPUnit\Framework\TestCase;

final class ActivityControllerTest extends TestCase
{
    use CreatesTestUsers;

    private ActivityController $controller;
    private string $email;

    protected function setUp(): void
    {
        $this->email = sprintf('activity-ctrl-%s@example.test', bin2hex(random_bytes(6)));
        $this->controller = new ActivityController(TwigFactory::create());
    }

    protected function tearDown(): void
    {
        Database::connection()->prepare('DELETE FROM users WHERE email = :email')->execute(['email' => $this->email]);
    }

    public function testIndexShowsThisTestsOwnFreshRegistrationEntry(): void
    {
        //arrange
        // Registering via AuthService writes a 'user_registered' activity_log
        // row (see AuthService::register) — a deterministic, test-owned
        // signal to look for, since findRecent(100) is globally scoped and
        // the shared test DB accumulates rows from every other test run.
        $this->registerOwner($this->email);

        //act
        $output = $this->controller->index([]);

        //assert
        self::assertStringContainsString('Activity Log', $output);
        self::assertStringContainsString($this->email, $output);
        self::assertStringContainsString('user_registered', $output);
    }
}

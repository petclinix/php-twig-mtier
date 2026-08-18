<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Domain\User;
use App\Http\Controller\Admin\UserController;
use App\Infrastructure\Database;
use App\Repository\UserRepository;
use App\Tests\Support\CreatesTestUsers;
use App\Tests\Support\HeaderSpy;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class UserControllerTest extends TestCase
{
    use CreatesTestUsers;

    private UserController $controller;
    private string $actorEmail;
    private string $targetEmail;
    private User $actor;
    private User $target;

    protected function setUp(): void
    {
        HeaderSpy::reset();
        $suffix = bin2hex(random_bytes(6));
        $this->actorEmail = "user-ctrl-actor-{$suffix}@example.test";
        $this->targetEmail = "user-ctrl-target-{$suffix}@example.test";
        $this->actor = $this->createAdminUser($this->actorEmail);
        $this->target = $this->createAdminUser($this->targetEmail);
        $this->loginAs($this->actor->id, 'admin');
        $this->controller = new UserController(new Environment(new FilesystemLoader(__DIR__ . '/../../templates')));
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:actor, :target)')
            ->execute(['actor' => $this->actorEmail, 'target' => $this->targetEmail]);
    }

    public function testIndexListsThisTestsFreshUser(): void
    {
        $output = $this->controller->index([]);

        self::assertStringContainsString('Users', $output);
        self::assertStringContainsString($this->targetEmail, $output);
    }

    public function testDeactivateSetsTargetInactiveAndRedirects(): void
    {
        $output = $this->controller->deactivate(['id' => (string) $this->target->id]);

        self::assertSame('', $output);
        self::assertSame('/admin/users', HeaderSpy::location());
        self::assertFalse((new UserRepository())->findById($this->target->id)->isActive);
    }

    public function testActivateSetsTargetActiveAgain(): void
    {
        (new UserRepository())->setActive($this->target->id, false);

        $output = $this->controller->activate(['id' => (string) $this->target->id]);

        self::assertSame('', $output);
        self::assertSame('/admin/users', HeaderSpy::location());
        self::assertTrue((new UserRepository())->findById($this->target->id)->isActive);
    }

    public function testDeactivateIsNoOpWhenActorTargetsSelf(): void
    {
        $output = $this->controller->deactivate(['id' => (string) $this->actor->id]);

        self::assertSame('', $output);
        self::assertSame('/admin/users', HeaderSpy::location());
        self::assertTrue((new UserRepository())->findById($this->actor->id)->isActive);
    }
}

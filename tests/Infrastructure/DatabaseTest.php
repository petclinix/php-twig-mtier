<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use App\Infrastructure\Database;
use App\Repository\ActivityLogRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseTest extends TestCase
{
    private const ACTION = 'database-test-nested-transaction';

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM activity_log WHERE action = :action')
            ->execute(['action' => self::ACTION]);
    }

    public function testNestedRunInTransactionRollsBackOuterWorkOnInnerFailure(): void
    {
        //arrange
        $activityLog = new ActivityLogRepository();

        //act
        try {
            Database::runInTransaction(function () use ($activityLog): void {
                $activityLog->record(null, self::ACTION);

                Database::runInTransaction(function (): void {
                    throw new RuntimeException('inner failure');
                });
            });
            self::fail('Expected the inner exception to propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('inner failure', $e->getMessage());
        }

        //assert — the outer call's own work must be rolled back too, since a
        // nested runInTransaction() joins the same transaction rather than
        // opening (and independently committing) its own
        self::assertSame(0, $this->countLoggedActions());
    }

    public function testNestedRunInTransactionCommitsTogetherOnSuccess(): void
    {
        //arrange
        $activityLog = new ActivityLogRepository();

        //act
        Database::runInTransaction(function () use ($activityLog): void {
            $activityLog->record(null, self::ACTION);

            Database::runInTransaction(function () use ($activityLog): void {
                $activityLog->record(null, self::ACTION);
            });
        });

        //assert
        self::assertSame(2, $this->countLoggedActions());
    }

    private function countLoggedActions(): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM activity_log WHERE action = :action');
        $stmt->execute(['action' => self::ACTION]);

        return (int) $stmt->fetchColumn();
    }
}

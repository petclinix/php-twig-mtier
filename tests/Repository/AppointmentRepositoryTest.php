<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Domain\AppointmentStatus;
use App\Domain\Owner;
use App\Domain\Vet;
use App\Infrastructure\Database;
use App\Repository\AppointmentRepository;
use App\Repository\Exception\AppointmentSlotUnavailableException;
use App\Repository\PetRepository;
use App\Tests\Support\CreatesTestUsers;
use DateTimeImmutable;
use PDOException;
use PHPUnit\Framework\TestCase;

final class AppointmentRepositoryTest extends TestCase
{
    use CreatesTestUsers;

    private AppointmentRepository $appointments;
    private string $ownerEmail;
    private string $vetEmail;
    private Owner $owner;
    private Vet $vet;
    private int $petId;
    private DateTimeImmutable $slot;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ownerEmail = "apptrepo-owner-{$suffix}@example.test";
        $this->vetEmail = "apptrepo-vet-{$suffix}@example.test";
        $this->owner = $this->registerOwner($this->ownerEmail);
        $this->vet = $this->registerVet($this->vetEmail);
        $this->petId = (new PetRepository())->create($this->owner->id, 'Rex', 'Dog', null, null)->id;
        $this->slot = new DateTimeImmutable('+1 week');

        $this->appointments = new AppointmentRepository();
    }

    protected function tearDown(): void
    {
        Database::connection()
            ->prepare('DELETE FROM users WHERE email IN (:owner, :vet)')
            ->execute(['owner' => $this->ownerEmail, 'vet' => $this->vetEmail]);
    }

    public function testCreateRejectsSecondActiveBookingOfSameVetSlot(): void
    {
        //arrange
        $this->appointments->create($this->petId, $this->vet->id, $this->slot, null);

        //assert
        $this->expectException(AppointmentSlotUnavailableException::class);

        //act
        $this->appointments->create($this->petId, $this->vet->id, $this->slot, null);
    }

    public function testCreateAllowsRebookingSameSlotAfterCancellation(): void
    {
        //arrange
        $first = $this->appointments->create($this->petId, $this->vet->id, $this->slot, null);
        $this->appointments->updateStatus($first->id, AppointmentStatus::Cancelled);

        //act
        $second = $this->appointments->create($this->petId, $this->vet->id, $this->slot, null);

        //assert
        self::assertSame(AppointmentStatus::Requested, $second->status);
    }

    public function testConcurrentBookingsOfSameSlotAreSerializedByTheDatabase(): void
    {
        //arrange — two independent connections, simulating two concurrent requests
        $connectionA = Database::newConnection();
        $connectionB = Database::newConnection();

        $insert = 'INSERT INTO appointments (pet_id, vet_id, scheduled_at, reason)
                    VALUES (:pet_id, :vet_id, :scheduled_at, NULL)';
        $params = [
            'pet_id' => $this->petId,
            'vet_id' => $this->vet->id,
            'scheduled_at' => $this->slot->format('Y-m-d H:i:s'),
        ];

        //act — A inserts but does not commit yet, holding the unique-key entry
        $connectionA->beginTransaction();
        $connectionA->prepare($insert)->execute($params);

        // B must not be allowed to proceed while A's conflicting insert is still
        // in flight. A short lock-wait timeout keeps the test fast instead of
        // hanging if this guarantee ever regresses.
        $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 2');
        $connectionB->beginTransaction();

        $blocked = false;
        try {
            $connectionB->prepare($insert)->execute($params);
        } catch (PDOException) {
            $blocked = true;
        } finally {
            if ($connectionB->inTransaction()) {
                $connectionB->rollBack();
            }
        }

        $connectionA->commit();

        //assert
        self::assertTrue($blocked, 'A second concurrent booking of the same slot must be blocked, not silently allowed through.');

        $stmt = $connectionA->prepare('SELECT COUNT(*) FROM appointments WHERE vet_id = :vet_id AND scheduled_at = :scheduled_at');
        $stmt->execute(['vet_id' => $this->vet->id, 'scheduled_at' => $this->slot->format('Y-m-d H:i:s')]);
        self::assertSame(1, (int) $stmt->fetchColumn());
    }
}

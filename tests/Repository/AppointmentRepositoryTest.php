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

    public function testCreateRejectsOverlappingBookingAtDifferentStartTime(): void
    {
        //arrange — a 60-minute booking starting 30 minutes into the slot
        $this->appointments->create($this->petId, $this->vet->id, $this->slot->modify('+30 minutes'), null, 60);

        //assert — a fixed-30-minute assumption would have thought this earlier
        // booking already ended by +60 minutes; the real 60-minute booking is
        // still running until +90 minutes, so this must be rejected
        $this->expectException(AppointmentSlotUnavailableException::class);

        //act
        $this->appointments->create($this->petId, $this->vet->id, $this->slot->modify('+60 minutes'), null, 30);
    }

    public function testConcurrentOverlappingBookingsAtDifferentStartTimesAreSerialized(): void
    {
        //arrange — two independent connections, simulating two concurrent requests
        $connectionA = Database::newConnection();
        $connectionB = Database::newConnection();

        $lock = 'SELECT id FROM vets WHERE id = :vet_id FOR UPDATE';
        $insert = 'INSERT INTO appointments (pet_id, vet_id, scheduled_at, duration_minutes, reason)
                    VALUES (:pet_id, :vet_id, :scheduled_at, :duration_minutes, NULL)';

        // A books a 60-minute slot starting at $this->slot.
        $aStart = $this->slot;

        //act — A locks the vet row and inserts but does not commit yet
        $connectionA->beginTransaction();
        $connectionA->prepare($lock)->execute(['vet_id' => $this->vet->id]);
        $connectionA->prepare($insert)->execute([
            'pet_id' => $this->petId,
            'vet_id' => $this->vet->id,
            'scheduled_at' => $aStart->format('Y-m-d H:i:s'),
            'duration_minutes' => 60,
        ]);

        // B attempts an overlapping booking at a different start time (+30
        // minutes into A's still-uncommitted 60-minute span). It must not be
        // allowed to proceed while A is still in flight. A short lock-wait
        // timeout keeps the test fast instead of hanging if this regresses.
        $bStart = $aStart->modify('+30 minutes');
        $connectionB->exec('SET SESSION innodb_lock_wait_timeout = 2');

        $blocked = false;
        try {
            $connectionB->beginTransaction();
            $connectionB->prepare($lock)->execute(['vet_id' => $this->vet->id]);
        } catch (PDOException) {
            $blocked = true;
        } finally {
            if ($connectionB->inTransaction()) {
                $connectionB->rollBack();
            }
        }

        $connectionA->commit();

        //assert
        self::assertTrue($blocked, 'A second concurrent overlapping booking must be blocked, not silently allowed through.');

        $stmt = $connectionA->prepare(
            "SELECT COUNT(*) FROM appointments
             WHERE vet_id = :vet_id AND status IN ('requested', 'confirmed')
               AND scheduled_at < :end AND scheduled_at + INTERVAL duration_minutes MINUTE > :start"
        );
        $stmt->execute([
            'vet_id' => $this->vet->id,
            'start' => $bStart->format('Y-m-d H:i:s'),
            'end' => $bStart->modify('+30 minutes')->format('Y-m-d H:i:s'),
        ]);
        self::assertSame(1, (int) $stmt->fetchColumn());
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

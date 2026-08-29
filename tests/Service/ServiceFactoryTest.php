<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ActivityLogService;
use App\Service\AdminService;
use App\Service\AppointmentAvailabilityService;
use App\Service\AppointmentTransitionService;
use App\Service\AuthService;
use App\Service\OwnerAppointmentBoardService;
use App\Service\OwnerVisitBoardService;
use App\Service\ServiceFactory;
use App\Service\VetAppointmentBoardService;
use App\Service\VisitService;
use PHPUnit\Framework\TestCase;

final class ServiceFactoryTest extends TestCase
{
    private ServiceFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ServiceFactory();
    }

    public function testAuthService(): void
    {
        self::assertInstanceOf(AuthService::class, $this->factory->authService());
    }

    public function testAdminService(): void
    {
        self::assertInstanceOf(AdminService::class, $this->factory->adminService());
    }

    public function testActivityLogService(): void
    {
        self::assertInstanceOf(ActivityLogService::class, $this->factory->activityLogService());
    }

    public function testAppointmentAvailabilityService(): void
    {
        self::assertInstanceOf(AppointmentAvailabilityService::class, $this->factory->appointmentAvailabilityService());
    }

    public function testAppointmentTransitionService(): void
    {
        self::assertInstanceOf(AppointmentTransitionService::class, $this->factory->appointmentTransitionService());
    }

    public function testOwnerAppointmentBoardService(): void
    {
        self::assertInstanceOf(OwnerAppointmentBoardService::class, $this->factory->ownerAppointmentBoardService());
    }

    public function testOwnerVisitBoardService(): void
    {
        self::assertInstanceOf(OwnerVisitBoardService::class, $this->factory->ownerVisitBoardService());
    }

    public function testVetAppointmentBoardService(): void
    {
        self::assertInstanceOf(VetAppointmentBoardService::class, $this->factory->vetAppointmentBoardService());
    }

    public function testVisitService(): void
    {
        self::assertInstanceOf(VisitService::class, $this->factory->visitService());
    }
}

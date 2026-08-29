<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ActivityLogRepository;
use App\Repository\AppointmentRepository;
use App\Repository\AvailabilityExceptionRepository;
use App\Repository\AvailabilityRepository;
use App\Repository\OwnerRepository;
use App\Repository\PetRepository;
use App\Repository\UserRepository;
use App\Repository\VetRepository;
use App\Repository\VisitRepository;

final class ServiceFactory
{
    public function authService(): AuthService
    {
        return new AuthService(
            new UserRepository(),
            new OwnerRepository(),
            new VetRepository(),
            new ActivityLogRepository(),
        );
    }

    public function adminService(): AdminService
    {
        return new AdminService(new UserRepository(), new ActivityLogRepository());
    }

    public function activityLogService(): ActivityLogService
    {
        return new ActivityLogService(new ActivityLogRepository(), new UserRepository());
    }

    public function appointmentAvailabilityService(): AppointmentAvailabilityService
    {
        return new AppointmentAvailabilityService(
            new AvailabilityRepository(),
            new AvailabilityExceptionRepository(),
            new AppointmentRepository(),
        );
    }

    public function appointmentTransitionService(): AppointmentTransitionService
    {
        return new AppointmentTransitionService(new AppointmentRepository(), new PetRepository());
    }

    public function ownerAppointmentBoardService(): OwnerAppointmentBoardService
    {
        return new OwnerAppointmentBoardService(
            new PetRepository(),
            new VetRepository(),
            new AppointmentRepository(),
            $this->appointmentAvailabilityService(),
        );
    }

    public function ownerVisitBoardService(): OwnerVisitBoardService
    {
        return new OwnerVisitBoardService(
            new PetRepository(),
            new VetRepository(),
            new AppointmentRepository(),
            new VisitRepository(),
        );
    }

    public function vetAppointmentBoardService(): VetAppointmentBoardService
    {
        return new VetAppointmentBoardService(
            new AppointmentRepository(),
            new PetRepository(),
            new OwnerRepository(),
        );
    }

    public function visitService(): VisitService
    {
        return new VisitService(new AppointmentRepository(), new VisitRepository());
    }
}

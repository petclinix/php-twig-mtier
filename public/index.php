<?php

declare(strict_types=1);

use App\Http\Controller\Admin\ActivityController as AdminActivityController;
use App\Http\Controller\Admin\StatsController as AdminStatsController;
use App\Http\Controller\Admin\UserController as AdminUserController;
use App\Http\Controller\AuthController;
use App\Http\Controller\DashboardController;
use App\Http\Controller\HomeController;
use App\Http\Controller\Owner\AppointmentController;
use App\Http\Controller\Owner\PetController;
use App\Http\Controller\Owner\VisitController;
use App\Http\Controller\Vet\AppointmentController as VetAppointmentController;
use App\Http\Controller\Vet\AvailabilityController as VetAvailabilityController;
use App\Http\Controller\Vet\VisitController as VetVisitController;
use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\CsrfMiddleware;
use App\Http\Middleware\OwnerMiddleware;
use App\Http\Middleware\VetMiddleware;
use App\Http\Router\Router;
use App\Http\Session;
use App\Http\TwigFactory;

require __DIR__ . '/../vendor/autoload.php';

Session::start();

$twig = TwigFactory::create();
$twig->addGlobal('current_user_role', Session::role());

$router = new Router();
$router->get('/', [HomeController::class, 'index']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register'], [CsrfMiddleware::class]);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login'], [CsrfMiddleware::class]);
$router->post('/logout', [AuthController::class, 'logout'], [CsrfMiddleware::class]);
$router->get('/dashboard', [DashboardController::class, 'index'], [AuthMiddleware::class]);

$ownerMiddleware = [AuthMiddleware::class, OwnerMiddleware::class, CsrfMiddleware::class];
$router->get('/owner/pets', [PetController::class, 'index'], $ownerMiddleware);
$router->post('/owner/pets', [PetController::class, 'store'], $ownerMiddleware);
$router->get('/owner/pets/{id:\d+}', [PetController::class, 'profile'], $ownerMiddleware);
$router->get('/owner/appointments', [AppointmentController::class, 'index'], $ownerMiddleware);
$router->post('/owner/appointments', [AppointmentController::class, 'store'], $ownerMiddleware);
$router->post('/owner/appointments/{id:\d+}/cancel', [AppointmentController::class, 'cancel'], $ownerMiddleware);
$router->get('/owner/appointments/{id:\d+}/reschedule', [AppointmentController::class, 'editReschedule'], $ownerMiddleware);
$router->post('/owner/appointments/{id:\d+}/reschedule', [AppointmentController::class, 'reschedule'], $ownerMiddleware);
$router->get('/owner/visits', [VisitController::class, 'index'], $ownerMiddleware);

$vetMiddleware = [AuthMiddleware::class, VetMiddleware::class, CsrfMiddleware::class];
$router->get('/vet/availability', [VetAvailabilityController::class, 'index'], $vetMiddleware);
$router->post('/vet/availability', [VetAvailabilityController::class, 'store'], $vetMiddleware);
$router->post('/vet/availability/{id:\d+}/delete', [VetAvailabilityController::class, 'destroy'], $vetMiddleware);
$router->post('/vet/availability/exceptions', [VetAvailabilityController::class, 'storeException'], $vetMiddleware);
$router->post('/vet/availability/exceptions/{id:\d+}/delete', [VetAvailabilityController::class, 'destroyException'], $vetMiddleware);
$router->get('/vet/appointments', [VetAppointmentController::class, 'index'], $vetMiddleware);
$router->post('/vet/appointments/{id:\d+}/confirm', [VetAppointmentController::class, 'confirm'], $vetMiddleware);
$router->post('/vet/appointments/{id:\d+}/cancel', [VetAppointmentController::class, 'cancel'], $vetMiddleware);
$router->post('/vet/appointments/{id:\d+}/no-show', [VetAppointmentController::class, 'markNoShow'], $vetMiddleware);
$router->get('/vet/appointments/{id:\d+}/visit', [VetVisitController::class, 'create'], $vetMiddleware);
$router->post('/vet/appointments/{id:\d+}/visit', [VetVisitController::class, 'store'], $vetMiddleware);

$adminMiddleware = [AuthMiddleware::class, AdminMiddleware::class, CsrfMiddleware::class];
$router->get('/admin', [AdminStatsController::class, 'index'], $adminMiddleware);
$router->get('/admin/users', [AdminUserController::class, 'index'], $adminMiddleware);
$router->post('/admin/users/{id:\d+}/activate', [AdminUserController::class, 'activate'], $adminMiddleware);
$router->post('/admin/users/{id:\d+}/deactivate', [AdminUserController::class, 'deactivate'], $adminMiddleware);
$router->get('/admin/activity', [AdminActivityController::class, 'index'], $adminMiddleware);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $twig);

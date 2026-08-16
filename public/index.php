<?php

declare(strict_types=1);

use App\Http\Controller\AuthController;
use App\Http\Controller\DashboardController;
use App\Http\Controller\HomeController;
use App\Http\Controller\Owner\AppointmentController;
use App\Http\Controller\Owner\PetController;
use App\Http\Controller\Owner\VisitController;
use App\Http\Controller\Vet\AppointmentController as VetAppointmentController;
use App\Http\Controller\Vet\AvailabilityController as VetAvailabilityController;
use App\Http\Controller\Vet\VisitController as VetVisitController;
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\OwnerMiddleware;
use App\Http\Middleware\VetMiddleware;
use App\Http\Router\Router;
use App\Http\Session;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

require __DIR__ . '/../vendor/autoload.php';

Session::start();

$twig = new Environment(
    new FilesystemLoader(__DIR__ . '/../templates'),
    ['cache' => false]
);
$twig->addGlobal('current_user_role', Session::role());

$router = new Router();
$router->get('/', [HomeController::class, 'index']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/dashboard', [DashboardController::class, 'index'], [AuthMiddleware::class]);

$ownerMiddleware = [AuthMiddleware::class, OwnerMiddleware::class];
$router->get('/owner/pets', [PetController::class, 'index'], $ownerMiddleware);
$router->post('/owner/pets', [PetController::class, 'store'], $ownerMiddleware);
$router->get('/owner/appointments', [AppointmentController::class, 'index'], $ownerMiddleware);
$router->post('/owner/appointments', [AppointmentController::class, 'store'], $ownerMiddleware);
$router->get('/owner/visits', [VisitController::class, 'index'], $ownerMiddleware);

$vetMiddleware = [AuthMiddleware::class, VetMiddleware::class];
$router->get('/vet/availability', [VetAvailabilityController::class, 'index'], $vetMiddleware);
$router->post('/vet/availability', [VetAvailabilityController::class, 'store'], $vetMiddleware);
$router->post('/vet/availability/{id:\d+}/delete', [VetAvailabilityController::class, 'destroy'], $vetMiddleware);
$router->get('/vet/appointments', [VetAppointmentController::class, 'index'], $vetMiddleware);
$router->post('/vet/appointments/{id:\d+}/confirm', [VetAppointmentController::class, 'confirm'], $vetMiddleware);
$router->post('/vet/appointments/{id:\d+}/cancel', [VetAppointmentController::class, 'cancel'], $vetMiddleware);
$router->get('/vet/appointments/{id:\d+}/visit', [VetVisitController::class, 'create'], $vetMiddleware);
$router->post('/vet/appointments/{id:\d+}/visit', [VetVisitController::class, 'store'], $vetMiddleware);

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $twig);

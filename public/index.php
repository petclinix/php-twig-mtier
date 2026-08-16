<?php

declare(strict_types=1);

use App\Http\Controller\AuthController;
use App\Http\Controller\DashboardController;
use App\Http\Controller\HomeController;
use App\Http\Middleware\AuthMiddleware;
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

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $twig);

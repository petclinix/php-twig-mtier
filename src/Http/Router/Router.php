<?php

declare(strict_types=1);

namespace App\Http\Router;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Twig\Environment;

use function FastRoute\simpleDispatcher;

final class Router
{
    /** @var list<array{0: string, 1: string, 2: array{0: class-string, 1: string}}> */
    private array $routes = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     */
    public function get(string $pattern, array $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function dispatch(string $method, string $uri, Environment $twig): void
    {
        $dispatcher = simpleDispatcher(function (RouteCollector $r): void {
            foreach ($this->routes as [$httpMethod, $pattern, $handler]) {
                $r->addRoute($httpMethod, $pattern, $handler);
            }
        });

        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $routeInfo = $dispatcher->dispatch($method, rawurldecode($path));

        switch ($routeInfo[0]) {
            case Dispatcher::NOT_FOUND:
                http_response_code(404);
                echo $twig->render('error/404.html.twig');
                break;

            case Dispatcher::METHOD_NOT_ALLOWED:
                http_response_code(405);
                echo $twig->render('error/405.html.twig');
                break;

            case Dispatcher::FOUND:
                [, $handler, $vars] = $routeInfo;
                [$class, $action] = $handler;
                $controller = new $class($twig);
                echo $controller->$action($vars);
                break;
        }
    }
}

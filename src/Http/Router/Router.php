<?php

declare(strict_types=1);

namespace App\Http\Router;

use App\Http\Middleware\Middleware;
use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Twig\Environment;

use function FastRoute\simpleDispatcher;

final class Router
{
    /** @var list<array{0: string, 1: string, 2: array{0: class-string, 1: string}, 3: list<class-string<Middleware>>}> */
    private array $routes = [];

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string<Middleware>> $middleware
     */
    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->routes[] = ['GET', $pattern, $handler, $middleware];
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string<Middleware>> $middleware
     */
    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->routes[] = ['POST', $pattern, $handler, $middleware];
    }

    public function dispatch(string $method, string $uri, Environment $twig): void
    {
        $dispatcher = simpleDispatcher(function (RouteCollector $r): void {
            foreach ($this->routes as [$httpMethod, $pattern, $handler, $middleware]) {
                $r->addRoute($httpMethod, $pattern, [$handler, $middleware]);
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
                [$routeData, $vars] = [$routeInfo[1], $routeInfo[2]];
                [[$class, $action], $middleware] = $routeData;

                foreach ($middleware as $middlewareClass) {
                    if (!(new $middlewareClass())->handle()) {
                        return;
                    }
                }

                $controller = new $class($twig);
                echo $controller->$action($vars);
                break;
        }
    }
}

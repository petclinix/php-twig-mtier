<?php

declare(strict_types=1);

namespace App\Tests\Http\Router;

use App\Http\Router\Router;
use App\Tests\Support\Router\BlockingMiddleware;
use App\Tests\Support\Router\FakeController;
use App\Tests\Support\Router\MiddlewareCallLog;
use App\Tests\Support\Router\PassingMiddleware;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class RouterTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(__DIR__ . '/../../../templates'));
        MiddlewareCallLog::reset();
    }

    private function dispatch(Router $router, string $method, string $uri): string
    {
        ob_start();
        $router->dispatch($method, $uri, $this->twig);

        return (string) ob_get_clean();
    }

    public function testFoundRouteInstantiatesControllerAndPassesRouteVarsThrough(): void
    {
        //arrange
        $router = new Router();
        $router->get('/items/{id:\d+}', [FakeController::class, 'respond']);

        //act
        $output = $this->dispatch($router, 'GET', '/items/42');

        //assert
        self::assertSame('controller-ran:42', $output);
    }

    public function testPostRouteIsMatchedSeparatelyFromGetRoute(): void
    {
        //arrange
        $router = new Router();
        $router->post('/items', [FakeController::class, 'respond']);

        //act
        $output = $this->dispatch($router, 'POST', '/items');

        //assert
        self::assertSame('controller-ran:', $output);
    }

    public function testUnknownRouteRenders404FromNotFoundBranch(): void
    {
        //arrange
        $router = new Router();
        $router->get('/items', [FakeController::class, 'respond']);

        //act
        $output = $this->dispatch($router, 'GET', '/does-not-exist');

        //assert
        self::assertStringContainsString('Page not found.', $output);
    }

    public function testKnownRouteWithWrongMethodRenders405FromMethodNotAllowedBranch(): void
    {
        //arrange
        $router = new Router();
        $router->get('/items', [FakeController::class, 'respond']);

        //act
        $output = $this->dispatch($router, 'POST', '/items');

        //assert
        self::assertStringContainsString('Method not allowed.', $output);
    }

    public function testQueryStringAndUrlEncodingAreIgnoredWhenMatchingThePath(): void
    {
        //arrange
        $router = new Router();
        $router->get('/items/{id:\d+}', [FakeController::class, 'respond']);

        //act
        $output = $this->dispatch($router, 'GET', '/%69tems/42?unused=1');

        //assert
        self::assertSame('controller-ran:42', $output);
    }

    public function testPassingMiddlewareLetsTheControllerRun(): void
    {
        //arrange
        $router = new Router();
        $router->get('/items', [FakeController::class, 'respond'], [PassingMiddleware::class]);

        //act
        $output = $this->dispatch($router, 'GET', '/items');

        //assert
        self::assertSame('controller-ran:', $output);
        self::assertSame([PassingMiddleware::class], MiddlewareCallLog::$calls);
    }

    public function testBlockingMiddlewareShortCircuitsBeforeTheControllerRuns(): void
    {
        //arrange
        $router = new Router();
        $router->get('/items', [FakeController::class, 'respond'], [BlockingMiddleware::class]);

        //act
        $output = $this->dispatch($router, 'GET', '/items');

        //assert
        self::assertSame('', $output);
        self::assertSame([BlockingMiddleware::class], MiddlewareCallLog::$calls);
    }

    public function testBlockingMiddlewareStopsAnyRemainingMiddlewareFromRunning(): void
    {
        //arrange
        $router = new Router();
        $router->get('/items', [FakeController::class, 'respond'], [BlockingMiddleware::class, PassingMiddleware::class]);

        //act
        $output = $this->dispatch($router, 'GET', '/items');

        //assert
        self::assertSame('', $output);
        self::assertSame([BlockingMiddleware::class], MiddlewareCallLog::$calls);
    }
}

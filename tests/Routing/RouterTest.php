<?php

namespace XyloIsCoding\CoconutCms\Tests\Routing;

use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;
use ReflectionParameter;
use XyloIsCoding\CoconutCms\Routing\Request;
use XyloIsCoding\CoconutCms\Routing\Route;
use XyloIsCoding\CoconutCms\Routing\RouteValueCaster;
use XyloIsCoding\CoconutCms\Routing\Router;

#[BackupGlobals(true)]
final class RouterTest extends TestCase
{
    private function requestFor(string $path): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = [];

        return Request::fromGlobals();
    }

    public function testDispatchTriesRoutesInOrderAndFirstMatchWins(): void
    {
        $calls = [];

        $router = new Router();
        $router->register(Route::simple(
            '/products/{id:\d+}',
            rule: fn (Request $r, int $id): bool => true,
            handler: function (Request $r, int $id) use (&$calls): void {
                $calls[] = "by-id:$id";
            },
        ));
        $router->register(Route::simple(
            '/products/{slug:[a-z-]+}',
            rule: fn (Request $r, string $slug): bool => true,
            handler: function (Request $r, string $slug) use (&$calls): void {
                $calls[] = "by-slug:$slug";
            },
        ));

        self::assertTrue($router->dispatch($this->requestFor('/products/42')));
        self::assertTrue($router->dispatch($this->requestFor('/products/nice-shoes')));
        self::assertSame(['by-id:42', 'by-slug:nice-shoes'], $calls);
    }

    public function testDispatchReturnsFalseWhenNoRouteMatches(): void
    {
        $router = new Router();
        $router->register(Route::simple(
            '/products/{id}',
            rule: fn (Request $r, string $id): bool => true,
            handler: fn (Request $r, string $id) => null,
        ));

        self::assertFalse($router->dispatch($this->requestFor('/nothing/here')));
    }

    public function testCustomCasterIsUsedForEveryRegisteredRoute(): void
    {
        $spyCaster = new class implements RouteValueCaster {
            public int $calls = 0;

            public function cast(string $value, ReflectionParameter $parameter): mixed
            {
                $this->calls++;
                return $value;
            }
        };

        $router = new Router($spyCaster);
        $router->register(Route::simple(
            '/products/{id}',
            rule: fn (Request $r, string $id): bool => true,
            handler: fn (Request $r, string $id) => null,
        ));

        $router->dispatch($this->requestFor('/products/42'));

        self::assertSame(1, $spyCaster->calls);
    }
}

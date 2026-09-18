<?php

namespace XyloIsCoding\CoconutCms\Tests\Routing;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Routing\DefaultRouteValueCaster;
use XyloIsCoding\CoconutCms\Routing\Request;
use XyloIsCoding\CoconutCms\Routing\Route;
use XyloIsCoding\CoconutCms\Routing\RouteData;

final readonly class RouteTestProductData extends RouteData
{
    public function __construct(
        public string $category,
        public int $id,
    ) {
    }
}

final class RouteTestNotRouteData
{
}

#[BackupGlobals(true)]
final class RouteTest extends TestCase
{
    private function requestFor(string $path): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = [];

        return Request::fromGlobals();
    }

    public function testStructuredRouteHydratesDataAndInvokesHandler(): void
    {
        $received = null;
        $route = Route::structured(
            '/products/{category}/{id}',
            RouteTestProductData::class,
            rule: fn (Request $r, RouteTestProductData $d): bool => true,
            handler: function (Request $r, RouteTestProductData $d) use (&$received): void {
                $received = $d;
            },
        );

        $handled = $route->handle($this->requestFor('/products/shoes/42'), new DefaultRouteValueCaster());

        self::assertTrue($handled);
        self::assertSame('shoes', $received->category);
        self::assertSame(42, $received->id);
    }

    public function testStructuredRouteRuleRejectingSkipsHandlerAndReturnsFalse(): void
    {
        $handlerCalled = false;
        $route = Route::structured(
            '/products/{category}/{id}',
            RouteTestProductData::class,
            rule: fn (Request $r, RouteTestProductData $d): bool => false,
            handler: function (Request $r, RouteTestProductData $d) use (&$handlerCalled): void {
                $handlerCalled = true;
            },
        );

        $handled = $route->handle($this->requestFor('/products/shoes/42'), new DefaultRouteValueCaster());

        self::assertFalse($handled);
        self::assertFalse($handlerCalled);
    }

    public function testStructuredRouteRejectsDataClassNotExtendingRouteData(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Route::structured(
            '/products/{id}',
            RouteTestNotRouteData::class,
            rule: fn () => true,
            handler: fn () => null,
        );
    }

    public function testStructuredRouteFailsFastWhenDataClassRequiresUncapturedParam(): void
    {
        $this->expectException(LogicException::class);
        Route::structured(
            '/tags/{name}',
            RouteTestProductData::class, // requires "category" and "id", pattern only has "name"
            rule: fn () => true,
            handler: fn () => null,
        );
    }

    public function testSimpleRouteCastsAndUnpacksNamedArgsPerClosure(): void
    {
        $ruleArgs = null;
        $handlerArgs = null;

        $route = Route::simple(
            '/tags/{name}/{id}',
            rule: function (Request $r, string $name) use (&$ruleArgs): bool {
                $ruleArgs = ['name' => $name];
                return true;
            },
            handler: function (Request $r, string $name, int $id) use (&$handlerArgs): void {
                $handlerArgs = ['name' => $name, 'id' => $id];
            },
        );

        $handled = $route->handle($this->requestFor('/tags/php/7'), new DefaultRouteValueCaster());

        self::assertTrue($handled);
        self::assertSame(['name' => 'php'], $ruleArgs);
        self::assertSame(['name' => 'php', 'id' => 7], $handlerArgs);
    }

    public function testSimpleRouteToleratesPatternSegmentNeitherClosureConsumes(): void
    {
        $route = Route::simple(
            '/products/{category}/{id}',
            rule: fn (Request $r, string $category): bool => true,
            handler: fn (Request $r, string $category) => null,
        );

        self::assertTrue($route->handle($this->requestFor('/products/shoes/42'), new DefaultRouteValueCaster()));
    }

    public function testSimpleRouteFailsFastOnConflictingTypesBetweenRuleAndHandler(): void
    {
        $this->expectException(LogicException::class);
        Route::simple(
            '/tags/{id}',
            rule: fn (Request $r, string $id): bool => true,
            handler: fn (Request $r, int $id) => null,
        );
    }

    public function testSimpleRouteFailsFastWhenRequiredClosureParamNotCaptured(): void
    {
        $this->expectException(LogicException::class);
        Route::simple(
            '/tags/{name}',
            rule: fn (Request $r, string $notInPattern): bool => true,
            handler: fn (Request $r, string $notInPattern) => null,
        );
    }

    public function testHandleReturnsFalseWhenPatternDoesNotMatch(): void
    {
        $route = Route::simple(
            '/products/{id}',
            rule: fn (Request $r, string $id): bool => true,
            handler: fn (Request $r, string $id) => null,
        );

        self::assertFalse($route->handle($this->requestFor('/other/path'), new DefaultRouteValueCaster()));
    }

    public function testInlineConstraintRestrictsWhichRouteHandles(): void
    {
        $byId = Route::simple(
            '/products/{id:\d+}',
            rule: fn (Request $r, int $id): bool => true,
            handler: fn (Request $r, int $id) => null,
        );

        self::assertFalse($byId->handle($this->requestFor('/products/nice-shoes'), new DefaultRouteValueCaster()));
        self::assertTrue($byId->handle($this->requestFor('/products/42'), new DefaultRouteValueCaster()));
    }
}

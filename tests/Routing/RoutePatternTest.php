<?php

namespace XyloIsCoding\CoconutCms\Tests\Routing;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Routing\RoutePattern;

final class RoutePatternTest extends TestCase
{
    public function testMatchesAndCapturesPlainPlaceholders(): void
    {
        $pattern = new RoutePattern('/products/{category}/{id}');

        self::assertSame(['category' => 'shoes', 'id' => '42'], $pattern->match('/products/shoes/42'));
    }

    public function testDoesNotMatchWhenShapeDiffers(): void
    {
        $pattern = new RoutePattern('/products/{category}/{id}');

        self::assertNull($pattern->match('/products/shoes'));
        self::assertNull($pattern->match('/products/shoes/42/extra'));
        self::assertNull($pattern->match('/other/shoes/42'));
    }

    public function testPatternAndParamNamesAccessors(): void
    {
        $pattern = new RoutePattern('/products/{category}/{id}');

        self::assertSame('/products/{category}/{id}', $pattern->pattern());
        self::assertSame(['category', 'id'], $pattern->paramNames());
    }

    public function testInlineConstraintRestrictsMatching(): void
    {
        $pattern = new RoutePattern('/products/{id:\d+}');

        self::assertSame(['id' => '42'], $pattern->match('/products/42'));
        self::assertNull($pattern->match('/products/abc'));
    }

    public function testConstraintToleratesOneLevelOfNestedBraces(): void
    {
        $pattern = new RoutePattern('/codes/{code:\d{3}}');

        self::assertSame(['code' => '123'], $pattern->match('/codes/123'));
        self::assertNull($pattern->match('/codes/12'));
        self::assertNull($pattern->match('/codes/1234'));
    }

    public function testConstraintWithLiteralColonInsideItself(): void
    {
        $pattern = new RoutePattern('/time/{range:\d{2}:\d{2}}');

        self::assertSame(['range' => '10:30'], $pattern->match('/time/10:30'));
    }

    public function testTwoCompetingRoutesDisambiguateByConstraint(): void
    {
        $byId = new RoutePattern('/products/{id:\d+}');
        $bySlug = new RoutePattern('/products/{slug:[a-z-]+}');

        self::assertSame(['id' => '42'], $byId->match('/products/42'));
        self::assertNull($bySlug->match('/products/42'));

        self::assertNull($byId->match('/products/nice-shoes'));
        self::assertSame(['slug' => 'nice-shoes'], $bySlug->match('/products/nice-shoes'));
    }
}

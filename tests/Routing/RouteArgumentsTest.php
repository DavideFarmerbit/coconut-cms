<?php

namespace XyloIsCoding\CoconutCms\Tests\Routing;

use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Routing\DefaultRouteValueCaster;
use XyloIsCoding\CoconutCms\Routing\Request;
use XyloIsCoding\CoconutCms\Routing\RouteArguments;

final class RouteArgumentsTest extends TestCase
{
    public function testAssertSatisfiedByNamesAllowsOptionalParamNotInNames(): void
    {
        $closure = fn (Request $r, string $used, string $unused = 'default') => null;

        RouteArguments::assertSatisfiedByNames(RouteArguments::ofClosure($closure), ['used'], 'label');

        $this->addToAssertionCount(1);
    }

    public function testAssertSatisfiedByNamesThrowsWhenRequiredParamMissing(): void
    {
        $closure = fn (Request $r, string $required) => null;

        $this->expectException(LogicException::class);
        RouteArguments::assertSatisfiedByNames(RouteArguments::ofClosure($closure), [], 'my-label');
    }

    public function testBuildCastsPresentValuesAndSkipsOptionalMissing(): void
    {
        $closure = fn (Request $r, int $id, string $extra = 'default') => null;

        $args = RouteArguments::build(
            RouteArguments::ofClosure($closure),
            ['id' => '42'],
            new DefaultRouteValueCaster(),
            'label',
        );

        self::assertSame(['id' => 42], $args);
    }

    public function testBuildThrowsWhenRequiredCaptureMissing(): void
    {
        $closure = fn (Request $r, int $id) => null;

        $this->expectException(LogicException::class);
        RouteArguments::build(RouteArguments::ofClosure($closure), [], new DefaultRouteValueCaster(), 'label');
    }

    public function testOfClassReturnsConstructorParameters(): void
    {
        $parameters = RouteArguments::ofClass(RouteArgumentsTestData::class);

        self::assertSame(['category', 'id'], array_map(static fn ($p) => $p->getName(), $parameters));
    }

    public function testOfClosureSkipsLeadingRequestParameter(): void
    {
        $closure = fn (Request $r, string $category, int $id) => null;

        $parameters = RouteArguments::ofClosure($closure);

        self::assertSame(['category', 'id'], array_map(static fn ($p) => $p->getName(), $parameters));
    }

    public function testNamesOfClosureReturnsNameSet(): void
    {
        $closure = fn (Request $r, string $category, int $id) => null;

        self::assertSame(['category' => true, 'id' => true], RouteArguments::namesOfClosure($closure));
    }

    public function testMergeCombinesDisjointParametersFromBothClosures(): void
    {
        $a = fn (Request $r, string $category) => null;
        $b = fn (Request $r, int $id) => null;

        $merged = RouteArguments::merge($a, $b);

        self::assertSame(['category', 'id'], array_map(static fn ($p) => $p->getName(), $merged));
    }

    public function testMergeAllowsSameNameWithAgreeingType(): void
    {
        $a = fn (Request $r, string $name) => null;
        $b = fn (Request $r, string $name, int $id) => null;

        $merged = RouteArguments::merge($a, $b);

        self::assertSame(['name', 'id'], array_map(static fn ($p) => $p->getName(), $merged));
    }

    public function testMergeThrowsWhenClosuresDisagreeOnSharedParameterType(): void
    {
        $a = fn (Request $r, string $id) => null;
        $b = fn (Request $r, int $id) => null;

        $this->expectException(LogicException::class);
        RouteArguments::merge($a, $b);
    }
}

final readonly class RouteArgumentsTestData
{
    public function __construct(
        public string $category,
        public int $id,
    ) {
    }
}

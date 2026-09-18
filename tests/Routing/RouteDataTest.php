<?php

namespace XyloIsCoding\CoconutCms\Tests\Routing;

use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Routing\RouteData;

final readonly class RouteDataTestProduct extends RouteData
{
    public function __construct(
        public string $category,
        public int $id,
        public bool $archived = false,
    ) {
    }
}

final readonly class RouteDataTestPartial extends RouteData
{
    public function __construct(
        public string $category,
    ) {
    }
}

final class RouteDataTest extends TestCase
{
    public function testFromCapturesHydratesWithCasting(): void
    {
        $data = RouteDataTestProduct::fromCaptures(['category' => 'shoes', 'id' => '42']);

        self::assertSame('shoes', $data->category);
        self::assertSame(42, $data->id);
        self::assertFalse($data->archived);
    }

    public function testFromCapturesUsesDefaultWhenOptionalCaptureMissing(): void
    {
        $data = RouteDataTestProduct::fromCaptures(['category' => 'shoes', 'id' => '42', 'archived' => 'true']);

        self::assertTrue($data->archived);
    }

    public function testFromCapturesThrowsWhenRequiredCaptureMissing(): void
    {
        $this->expectException(LogicException::class);
        RouteDataTestProduct::fromCaptures(['category' => 'shoes']);
    }

    public function testFromCapturesIgnoresCapturesItDoesNotDeclare(): void
    {
        $data = RouteDataTestPartial::fromCaptures(['category' => 'shoes', 'id' => '42']);

        self::assertSame('shoes', $data->category);
    }

    public function testAssertSatisfiedByCaptureNamesPassesWhenRequiredParamsCovered(): void
    {
        RouteDataTestProduct::assertSatisfiedByCaptureNames(['category', 'id']);

        $this->addToAssertionCount(1);
    }

    public function testAssertSatisfiedByCaptureNamesToleratesUnconsumedPatternSegment(): void
    {
        RouteDataTestPartial::assertSatisfiedByCaptureNames(['category', 'id']);

        $this->addToAssertionCount(1);
    }

    public function testAssertSatisfiedByCaptureNamesThrowsWhenRequiredParamNotCaptured(): void
    {
        $this->expectException(LogicException::class);
        RouteDataTestProduct::assertSatisfiedByCaptureNames(['category']);
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Tests\Routing;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionParameter;
use UnexpectedValueException;
use XyloIsCoding\CoconutCms\Routing\DefaultRouteValueCaster;

enum TestSuitSortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}

enum TestSuitLevel: int
{
    case Low = 1;
    case High = 2;
}

final class DefaultRouteValueCasterTest extends TestCase
{
    private DefaultRouteValueCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new DefaultRouteValueCaster();
    }

    private function parameterOfType(string $type): ReflectionParameter
    {
        $closure = eval("return function ($type \$x) {};");

        return (new ReflectionFunction($closure))->getParameters()[0];
    }

    public function testCastsValidInt(): void
    {
        self::assertSame(42, $this->caster->cast('42', $this->parameterOfType('int')));
        self::assertSame(-7, $this->caster->cast('-7', $this->parameterOfType('int')));
    }

    public function testRejectsInvalidInt(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->caster->cast('abc', $this->parameterOfType('int'));
    }

    public function testRejectsNonIntegerNumericStringAsInt(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->caster->cast('4.2', $this->parameterOfType('int'));
    }

    public function testCastsValidFloat(): void
    {
        self::assertSame(4.2, $this->caster->cast('4.2', $this->parameterOfType('float')));
    }

    public function testRejectsInvalidFloat(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->caster->cast('abc', $this->parameterOfType('float'));
    }

    public function testCastsValidBool(): void
    {
        self::assertTrue($this->caster->cast('1', $this->parameterOfType('bool')));
        self::assertTrue($this->caster->cast('true', $this->parameterOfType('bool')));
        self::assertFalse($this->caster->cast('0', $this->parameterOfType('bool')));
        self::assertFalse($this->caster->cast('false', $this->parameterOfType('bool')));
    }

    public function testRejectsAmbiguousBoolInsteadOfTreatingItAsTruthy(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->caster->cast('banana', $this->parameterOfType('bool'));
    }

    public function testPassesThroughStringAndMixedUnchanged(): void
    {
        self::assertSame('hello', $this->caster->cast('hello', $this->parameterOfType('string')));
        self::assertSame('hello', $this->caster->cast('hello', $this->parameterOfType('mixed')));
    }

    public function testCastsValidStringBackedEnumCase(): void
    {
        self::assertSame(
            TestSuitSortDirection::Asc,
            $this->caster->cast('asc', $this->parameterOfType(TestSuitSortDirection::class)),
        );
    }

    public function testRejectsInvalidStringBackedEnumCase(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->caster->cast('sideways', $this->parameterOfType(TestSuitSortDirection::class));
    }

    public function testCastsValidIntBackedEnumCase(): void
    {
        self::assertSame(
            TestSuitLevel::High,
            $this->caster->cast('2', $this->parameterOfType(TestSuitLevel::class)),
        );
    }

    public function testRejectsNonIntegerValueForIntBackedEnum(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->caster->cast('abc', $this->parameterOfType(TestSuitLevel::class));
    }

    public function testRejectsOutOfRangeIntBackedEnumCase(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->caster->cast('99', $this->parameterOfType(TestSuitLevel::class));
    }

    public function testRejectsUnsupportedType(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->caster->cast('42', $this->parameterOfType('array'));
    }
}

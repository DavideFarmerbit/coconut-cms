<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Values;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValuesBuilder;

final class FieldValuesTest extends TestCase
{
    private static function path(string $path): FieldPath {
        return FieldPath::fromString($path);
    }

    public function testGetReturnsTheValueAtAPath(): void {
        $values = FieldValues::fromArray(['engine.horsepower' => 500]);

        self::assertSame(500, $values->get(self::path('engine.horsepower')));
    }

    public function testANullIsHeldNotAbsent(): void {
        $values = FieldValues::fromArray(['engine' => null]);

        self::assertTrue($values->has(self::path('engine')));
        self::assertNull($values->get(self::path('engine')));
        self::assertFalse($values->has(self::path('chassis')));
    }

    public function testGetOnAnAbsentPathThrows(): void {
        $this->expectException(InvalidArgumentException::class);

        FieldValues::empty()->get(self::path('engine'));
    }

    public function testUnderKeepsThePathAndEverythingBelowIt(): void {
        $values = FieldValues::fromArray([
            'pistons' => 2,
            'pistons.0.diameter' => 10,
            'pistons.1.diameter' => 12,
            'pistonsCount' => 7,
            'tags' => 1,
        ]);

        self::assertSame(
            ['pistons' => 2, 'pistons.0.diameter' => 10, 'pistons.1.diameter' => 12],
            $values->under(self::path('pistons'))->toArray(),
        );
    }

    public function testUnderFollowsAnEmbedToo(): void {
        $values = FieldValues::fromArray(['chassis:engine:x' => 1, 'chassisX' => 2]);

        self::assertSame(['chassis:engine:x' => 1], $values->under(self::path('chassis'))->toArray());
    }

    public function testWithinKeysWhatAnOwnedObjectHoldsFromItself(): void {
        $values = FieldValues::fromArray([
            'engine' => 'Engine',
            'engine.horsepower' => 500,
            'engine.piston:diameter' => 12,
            'engineer' => 'x',
        ]);

        self::assertSame(
            ['horsepower' => 500, 'piston:diameter' => 12],
            $values->within(self::path('engine'))->toArray(),
        );
    }

    public function testWithinWorksForACollectionItem(): void {
        $values = FieldValues::fromArray(['wheels' => 2, 'wheels.0.size' => 17, 'wheels.1.size' => 19]);

        self::assertSame(['size' => 19], $values->within(self::path('wheels.1'))->toArray());
    }

    public function testIterationYieldsFieldPathKeys(): void {
        $seen = [];
        foreach (FieldValues::fromArray(['engines.0.horsepower' => 400]) as $path => $value) {
            self::assertInstanceOf(FieldPath::class, $path);
            $seen[(string) $path] = $value;
        }

        self::assertSame(['engines.0.horsepower' => 400], $seen);
    }

    public function testTheBuilderReplacesAndBuildsAnImmutableResult(): void {
        $builder = new FieldValuesBuilder();
        $builder->set(self::path('horsepower'), 300);
        $built = $builder->build();
        $builder->set(self::path('horsepower'), 500);

        self::assertSame(300, $built->get(self::path('horsepower')));
        self::assertSame(500, $builder->build()->get(self::path('horsepower')));
    }
}

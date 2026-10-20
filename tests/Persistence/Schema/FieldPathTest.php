<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPath;

final class FieldPathTest extends TestCase
{
    public function testOfIsASingleSegmentWithNoTail(): void {
        $path = FieldPath::of(FieldName::of('horsepower'));

        self::assertSame('horsepower', (string) $path);
        self::assertEquals(FieldName::of('horsepower'), $path->head());
        self::assertNull($path->headJoin());
        self::assertNull($path->tail());
    }

    public function testEmbedJoinsWithAColonAndTraverseWithADot(): void {
        $path = FieldPath::of(FieldName::of('engine'))
            ->traverse(FieldName::of('piston'))
            ->embed(FieldName::of('diameter'));

        self::assertSame('engine.piston:diameter', (string) $path);
    }

    public function testTraverseTakesACollectionIndex(): void {
        $path = FieldPath::of(FieldName::of('engines'))->traverse(0)->traverse(FieldName::of('horsepower'));

        self::assertSame('engines.0.horsepower', (string) $path);
    }

    /** @return array<string, array{string}> */
    public static function validPaths(): array {
        return [
            'single field' => ['horsepower'],
            'embed chain' => ['chassis:engine:piston:diameter'],
            'owned, then embed' => ['engine.piston:diameter'],
            'collection item' => ['engines.0.horsepower'],
            'scalar collection item' => ['tags.1'],
            'underscore inside a name' => ['piston_diameter'],
        ];
    }

    #[DataProvider('validPaths')]
    public function testFromStringRoundTrips(string $path): void {
        self::assertSame($path, (string) FieldPath::fromString($path));
    }

    public function testFromStringBuildsTheSamePathAsBuildingItSegmentBySegment(): void {
        $built = FieldPath::of(FieldName::of('engines'))->traverse(0)->traverse(FieldName::of('piston'))->embed(FieldName::of('diameter'));

        self::assertEquals($built, FieldPath::fromString('engines.0.piston:diameter'));
    }

    public function testHeadJoinAndTailWalkThePathOneSegmentAtATime(): void {
        $path = FieldPath::fromString('engines.0.piston:diameter');

        self::assertEquals(FieldName::of('engines'), $path->head());
        self::assertSame(FieldPath::TRAVERSE, $path->headJoin());

        $item = $path->tail();
        self::assertSame(0, $item->head());
        self::assertSame('0.piston:diameter', (string) $item);

        $piston = $item->tail();
        self::assertEquals(FieldName::of('piston'), $piston->head());
        self::assertSame(FieldPath::EMBED, $piston->headJoin());
        self::assertSame('diameter', (string) $piston->tail());
    }

    /** @return array<string, array{string}> */
    public static function invalidPaths(): array {
        return [
            'empty' => [''],
            'leading separator' => ['.engine'],
            'trailing separator' => ['engine.'],
            'doubled separator' => ['engine..horsepower'],
            'index after an embed' => ['chassis:0'],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function testFromStringRejectsAMalformedPath(string $path): void {
        $this->expectException(InvalidArgumentException::class);

        FieldPath::fromString($path);
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValueMark;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/** Values moved all at once: each from the paths as they were before any moved. */
final class MoveAllTest extends TestCase
{
    /** A boat holding `$values`, each touched, owning an engine at "engine". */
    private static function boat(array $values): AggregateValues {
        return new AggregateValues('shop:boat', $values, array_map(static fn (): ValueMark => ValueMark::Touched, $values), ['engine' => 'e1']);
    }

    /** @return list<array{FieldPath, FieldPath}> */
    private static function moves(string ...$pairs): array {
        return array_map(static fn (string $pair): array => array_map(FieldPath::fromString(...), explode('>', $pair)), $pairs);
    }

    public function testTwoPathsSwapWhatTheyHoldAndWhatsUnder(): void {
        $values = self::boat(['length' => 12, 'sail:area' => 20, 'beam' => 4, 'name' => 'Sea']);

        $values->moveAll(self::moves('sail>beam', 'beam>sail'));

        self::assertSame(['length' => 12, 'beam:area' => 20, 'sail' => 4, 'name' => 'Sea'], $values->values());
        self::assertSame(ValueMark::Touched, $values->markOf(FieldPath::fromString('beam:area')));
    }

    public function testALoopOfAnyLengthRotates(): void {
        $values = self::boat(['a' => 1, 'b' => 2, 'c' => 3]);

        $values->moveAll(self::moves('a>b', 'b>c', 'c>a'));

        self::assertEquals(['b' => 1, 'c' => 2, 'a' => 3], $values->values());
    }

    public function testAMoveReplacesWhatWasWhereItGoesUnlessThatMovesAway(): void {
        $values = self::boat(['length' => 12, 'beam' => 4, 'beam:unit' => 'm']);

        $values->moveAll(self::moves('length>beam'));

        self::assertSame(['beam' => 12], $values->values(), 'The beam and what was under it are replaced.');
    }

    public function testOwnedObjectsMoveWithTheirSlot(): void {
        $values = self::boat(['engine' => 'shop:engine', 'engine.power' => 90, 'motor' => null]);

        $values->moveAll(self::moves('engine>motor', 'motor>engine'));

        self::assertSame(['motor' => 'e1'], $values->ownedIds());
        self::assertSame(['motor' => 'shop:engine', 'motor.power' => 90, 'engine' => null], $values->values());
    }
}

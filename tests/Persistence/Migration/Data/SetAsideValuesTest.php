<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data;

use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrence;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\OccurrenceKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValueMark;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/** A field set aside is captured on the entity holding it, at the site where its type sits, then removed. */
final class SetAsideValuesTest extends TestCase
{
    /** A boat with a hull, an owned engine embedding a chassis, and two labels as Embed items. */
    private static function boat(): AggregateValues {
        $values = [
            'hull' => 'steel',
            'engine' => 'ed:engine',
            'engine.horsepower' => 300,
            'engine.chassis:weight' => 900,
            'labels' => 2,
            'labels.0.text' => 'red',
            'labels.1.text' => 'fast',
        ];
        $marks = array_map(static fn (): ValueMark => ValueMark::Touched, $values);
        $marks['engine.chassis:weight'] = ValueMark::Untouched;

        return new AggregateValues('ed:boat', $values, $marks, ['engine' => 'engine-id']);
    }

    public function testAFieldOfTheRootIsCapturedOnTheRootItself(): void {
        $boat = self::boat();
        $boat->setAside('record', new Occurrence(null, 'ed:boat', OccurrenceKind::Root, FieldPath::TRAVERSE), FieldName::of('hull'));

        self::assertSame(['' => ['record' => ['' => ['values' => ['' => 'steel'], 'touched' => [''], 'ownedIds' => []]]]], $boat->setAsideCaptures());
        self::assertFalse($boat->has(FieldPath::fromString('hull')));
    }

    public function testAFieldOfAnOwnedObjectIsCapturedOnThatObject(): void {
        $boat = self::boat();
        $boat->setAside('record', new Occurrence(FieldPath::fromString('engine'), 'ed:engine', OccurrenceKind::Owned, FieldPath::TRAVERSE), FieldName::of('horsepower'));

        self::assertSame(['' => 300], $boat->setAsideCaptures()['engine-id']['record']['']['values'] ?? null);
    }

    public function testAnEmbedInsideAnOwnedObjectIsCapturedOnThatObjectAtItsSite(): void {
        $boat = self::boat();
        $boat->setAside('record', new Occurrence(FieldPath::fromString('engine.chassis'), 'ed:chassis', OccurrenceKind::Embedded, FieldPath::EMBED), FieldName::of('weight'));

        self::assertSame(['values' => ['' => 900], 'touched' => [], 'ownedIds' => []], $boat->setAsideCaptures()['engine-id']['record']['chassis'] ?? null);
    }

    public function testEmbedItemsAreCapturedOnePerSiteUnderOneRecord(): void {
        $boat = self::boat();
        foreach ([0, 1] as $index) {
            $boat->setAside('record', new Occurrence(FieldPath::fromString('labels.' . $index), 'ed:label', OccurrenceKind::Embedded, FieldPath::TRAVERSE), FieldName::of('text'));
        }

        $sites = $boat->setAsideCaptures()['']['record'] ?? [];
        self::assertSame(['labels.0', 'labels.1'], array_keys($sites));
        self::assertSame(['' => 'fast'], $sites['labels.1']['values']);
    }

    public function testAnOwnedObjectSetAsideIsCapturedWholeWithItsId(): void {
        $boat = self::boat();
        $boat->setAside('record', new Occurrence(null, 'ed:boat', OccurrenceKind::Root, FieldPath::TRAVERSE), FieldName::of('engine'));

        $capture = $boat->setAsideCaptures()['']['record'][''];
        self::assertSame(['' => 'ed:engine', '.horsepower' => 300, '.chassis:weight' => 900], $capture['values']);
        self::assertSame(['' => 'engine-id'], $capture['ownedIds']);
        self::assertNull($boat->ownedIdAt(FieldPath::fromString('engine')), 'Gone from the aggregate: its rows are deleted.');
    }

    public function testADefaultInstanceOnlyRemovesIt(): void {
        $defaults = AggregateValues::ofDefaults('ed:boat', ['hull' => 'wood'], ['hull']);
        $defaults->setAside('record', new Occurrence(null, 'ed:boat', OccurrenceKind::Root, FieldPath::TRAVERSE), FieldName::of('hull'));

        self::assertSame([], $defaults->setAsideCaptures());
        self::assertSame([], $defaults->ownValues());
    }
}

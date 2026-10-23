<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\CollectionLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\OwnedLayout;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Unique;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;

#[Entity]
final class LayoutHolder
{
    #[OwningReference]
    private ?LayoutEngine $engine = null;
}

#[Entity]
final class LayoutWheelWithHolder
{
    #[Embed]
    private LayoutHolder $holder;

    public function __construct() {
        $this->holder = new LayoutHolder();
    }
}

#[Entity]
#[Unique('tags')]
final class LayoutUniqueCollection
{
    #[Collection(itemKind: FieldKind::Scalar, itemScalarType: ScalarType::String)]
    private array $tags = [];
}

#[Entity]
#[Unique('body')]
final class LayoutUniqueText
{
    #[Scalar(ScalarType::Text)]
    private string $body = '';
}

#[Entity]
#[Unique('notes')]
final class LayoutUniqueNotes
{
    #[ValueObject]
    private LayoutNotes $notes;

    public function __construct() {
        $this->notes = new LayoutNotes('');
    }
}

final class LayoutBuilderTest extends TestCase
{
    /** @return array<string, string> Column name keyed by path, for every value column. */
    private static function columnsByPath(array $columns): array {
        $byPath = [];
        foreach ($columns as $column) {
            $byPath[(string) $column->path] = $column->name;
        }

        return $byPath;
    }

    private static function car(): LevelLayout {
        return LayoutFixtures::carBuilder()->levelOf('default:layout_car');
    }

    private static function collectionAt(LevelLayout $level, string $path): CollectionLayout {
        foreach ($level->collections as $collection) {
            if ((string) $collection->path === $path) {
                return $collection;
            }
        }

        self::fail(sprintf('No collection at "%s".', $path));
    }

    public function testALevelHoldsOnlyItsOwnFieldsNotItsParents(): void {
        $car = self::car();

        self::assertSame('car', $car->table);
        self::assertSame('_state__car', $car->stateTable);
        self::assertArrayNotHasKey('name', self::columnsByPath($car->columns));
    }

    public function testEmbedsFlattenIntoTheLevelsOwnColumnsJoinedWithDoubleUnderscores(): void {
        self::assertSame([
            'color' => 'color',
            'fuel' => 'fuel',
            'chassis:weight' => 'chassis__weight',
            'chassis:maker' => 'chassis__maker',
        ], self::columnsByPath(self::car()->columns));
    }

    public function testOwningReferencesHaveNoColumnAndOwnedCollectionsHaveACount(): void {
        $owned = array_map(
            static fn (OwnedLayout $owned): array => [$owned->ownerField(), $owned->countColumn],
            self::car()->owned,
        );

        self::assertSame([['chassis:spare', null], ['engine', null], ['engines', '_engines_count']], $owned);
    }

    public function testEveryOtherCollectionGetsItsOwnTableNamedAfterItsPath(): void {
        $tables = array_map(static fn (CollectionLayout $collection): string => $collection->table, self::car()->collections);

        self::assertSame(['car__chassis__tags', 'car__wheels', 'car__makers'], $tables);
    }

    public function testAScalarOrReferenceItemIsOneValueColumnWithNoStateTable(): void {
        $makers = self::collectionAt(self::car(), 'makers');

        self::assertNull($makers->stateTable);
        self::assertCount(1, $makers->itemColumns);
        self::assertNull($makers->itemColumns[0]->path);
        self::assertSame('_value', $makers->itemColumns[0]->name);
        self::assertSame(FieldKind::Reference, $makers->itemColumns[0]->field->kind());
    }

    public function testAnEmbedItemFlattensIntoItemColumnsWithItsOwnStateTable(): void {
        $wheels = self::collectionAt(self::car(), 'wheels');

        self::assertSame('_state__car__wheels', $wheels->stateTable);
        self::assertSame(['size' => 'size', 'maker' => 'maker'], self::columnsByPath($wheels->itemColumns));
    }

    public function testTheStateTableFlagsEveryPathAnInstanceCanHold(): void {
        self::assertSame([
            'color' => 'color',
            'fuel' => 'fuel',
            'chassis:weight' => 'chassis__weight',
            'chassis:maker' => 'chassis__maker',
            'chassis:spare' => 'chassis__spare',
            'engine' => 'engine',
            'engines' => 'engines',
            'chassis:tags' => 'chassis__tags',
            'wheels' => 'wheels',
            'makers' => 'makers',
        ], self::car()->stateColumns());
    }

    public function testUniqueGroupsAreScopedToTheOwnerAndRepeatedAtEveryEmbedSite(): void {
        self::assertSame([
            ['_owner', '_owner_field', 'chassis__maker'],
            ['_owner', '_owner_field', 'color', 'fuel'],
        ], self::car()->uniqueIndexes);
    }

    public function testAnEmbedItemsUniqueGroupIsScopedToTheCollectionsOwner(): void {
        self::assertSame([['_owner', 'size']], self::collectionAt(self::car(), 'wheels')->uniqueIndexes);
    }

    public function testAUniqueGroupOverAnEmbedCoversEveryColumnItFlattensInto(): void {
        $engine = LayoutFixtures::carBuilder()->levelOf('default:layout_engine');

        self::assertSame([['_owner', '_owner_field', 'serial']], $engine->uniqueIndexes);
        self::assertSame(['serial' => 'serial', 'piston:diameter' => 'piston__diameter'], self::columnsByPath($engine->columns));
    }

    public function testAnOwningReferenceInsideASingularEmbedIsFine(): void {
        $builder = LayoutFixtures::builderFor([LayoutPiston::class, LayoutEngine::class, LayoutHolder::class, LayoutWheelWithHolder::class]);

        self::assertSame('holder:engine', $builder->levelOf('default:layout_wheel_with_holder')->owned[0]->ownerField());
    }

    /** @return array<string, array{class-string}> */
    public static function uniqueGroupsOverText(): array {
        return [
            'a Text scalar' => [LayoutUniqueText::class],
            'a value object that is not indexable' => [LayoutUniqueNotes::class],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('uniqueGroupsOverText')]
    public function testAUniqueGroupOverAColumnStoredAsTextIsRejected(string $class): void {
        $builder = LayoutFixtures::builderFor([$class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stored as TEXT');

        $builder->levelOf(NativeIdentifiers::of($class));
    }

    public function testAUniqueGroupOverACollectionIsRejected(): void {
        $builder = LayoutFixtures::builderFor([LayoutUniqueCollection::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no column to index');

        $builder->levelOf('default:layout_unique_collection');
    }
}

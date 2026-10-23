<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Entity\Layout;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\DBAL\Types\Type;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;

#[Entity]
final class LayoutPart
{
    #[Scalar(ScalarType::Int)]
    private int $b = 0;
}

#[Entity]
final class LayoutCollidingColumns
{
    #[Scalar(ScalarType::Int)]
    private int $a__b = 0;

    #[Embed]
    private LayoutPart $a;

    public function __construct() {
        $this->a = new LayoutPart();
    }
}

#[Entity(table: 'entities')]
final class LayoutEntitiesTable
{
}

#[Entity]
final class LayoutLongName
{
    #[Scalar(ScalarType::Int)]
    private int $aFieldNameThatIsFarTooLongToBeAColumnNameInMysqlBecauseOfItsLimit = 0;
}

final class DatabaseSchemaTest extends TestCase
{
    private static function carSchema(): Schema {
        $builder = LayoutFixtures::carBuilder();
        $levels = [];
        foreach ([LayoutMaker::class, LayoutPiston::class, LayoutEngine::class, LayoutWheel::class, LayoutChassis::class, LayoutVehicle::class, LayoutCar::class] as $class) {
            $levels[] = $builder->levelOf(NativeIdentifiers::of($class));
        }

        return DatabaseSchema::of($levels);
    }

    /** @param class-string $class */
    private static function schemaOf(string $class): Schema {
        return DatabaseSchema::of([LayoutFixtures::builderFor([LayoutPart::class, $class])->levelOf(NativeIdentifiers::of($class))]);
    }

    /** @return list<string> "local -> foreign table (onDelete)", one per foreign key. */
    private static function foreignKeysOf(Schema $schema, string $table): array {
        return array_values(array_map(
            static fn (ForeignKeyConstraint $key): string => sprintf(
                '%s -> %s (%s)',
                implode(',', $key->getLocalColumns()),
                $key->getForeignTableName(),
                // Doctrine reports RESTRICT, MySQL's own default, as no action at all.
                $key->onDelete() ?? 'RESTRICT',
            ),
            $schema->getTable($table)->getForeignKeys(),
        ));
    }

    /** @return list<string> */
    private static function columnNamesOf(Table $table): array {
        return array_map(static fn (Column $column): string => $column->getName(), $table->getColumns());
    }

    public function testEveryTableTheLayoutsNeedIsCreated(): void {
        $tables = array_map(static fn ($table): string => $table->getName(), self::carSchema()->getTables());
        sort($tables);

        self::assertSame([
            '_state__car',
            '_state__car__wheels',
            '_state__layout_chassis',
            '_state__layout_engine',
            '_state__layout_maker',
            '_state__layout_piston',
            '_state__layout_vehicle',
            '_state__layout_wheel',
            'car',
            'car__chassis__tags',
            'car__makers',
            'car__wheels',
            'entities',
            'layout_chassis',
            'layout_chassis__tags',
            'layout_engine',
            'layout_maker',
            'layout_piston',
            'layout_vehicle',
            'layout_wheel',
        ], $tables);
    }

    public function testALevelTableHoldsItsIdOwnerCopiesValuesAndCounts(): void {
        $car = self::carSchema()->getTable('car');

        self::assertSame(
            ['_id', '_owner', '_owner_field', 'color', 'fuel', 'chassis__weight', 'chassis__maker', '_engines_count'],
            self::columnNamesOf($car),
        );
    }

    public function testEveryContentColumnIsNullable(): void {
        $car = self::carSchema()->getTable('car');

        foreach (['color', 'fuel', 'chassis__weight', 'chassis__maker'] as $column) {
            self::assertFalse($car->getColumn($column)->getNotnull(), $column);
        }

        self::assertTrue($car->getColumn('_engines_count')->getNotnull());
    }

    public function testColumnTypesFollowTheFieldKind(): void {
        $schema = self::carSchema();

        self::assertSame(Type::getType(Types::STRING), $schema->getTable('car')->getColumn('color')->getType());
        self::assertSame(255, $schema->getTable('car')->getColumn('color')->getLength());
        self::assertSame(Type::getType(Types::BIGINT), $schema->getTable('car')->getColumn('fuel')->getType());
        self::assertSame(Type::getType(Types::GUID), $schema->getTable('car')->getColumn('chassis__maker')->getType());
        self::assertSame(Type::getType(Types::DECIMAL), $schema->getTable('layout_engine')->getColumn('piston__diameter')->getType());
        self::assertSame(20, $schema->getTable('layout_engine')->getColumn('piston__diameter')->getPrecision());
        self::assertSame(6, $schema->getTable('layout_engine')->getColumn('piston__diameter')->getScale());
    }

    public function testAValueObjectThatIsNotIndexableIsStoredAsText(): void {
        $schema = DatabaseSchema::of([LayoutFixtures::builderFor([LayoutNoted::class])->levelOf('default:layout_noted')]);

        self::assertSame(Type::getType(Types::TEXT), $schema->getTable('layout_noted')->getColumn('notes')->getType());
    }

    public function testForeignKeysCascadeFromTheOwnerAndNullAReferencesTarget(): void {
        $schema = self::carSchema();

        self::assertSame(['owner -> entities (RESTRICT)'], self::foreignKeysOf($schema, 'entities'));
        self::assertSame(['_id -> entities (CASCADE)', 'chassis__maker -> entities (SET NULL)'], self::foreignKeysOf($schema, 'car'));
        self::assertSame(['_id -> car (CASCADE)'], self::foreignKeysOf($schema, '_state__car'));
        self::assertSame(['_owner -> car (CASCADE)', '_value -> entities (SET NULL)'], self::foreignKeysOf($schema, 'car__makers'));
        self::assertSame(['_owner,_position -> car__wheels (CASCADE)'], self::foreignKeysOf($schema, '_state__car__wheels'));
    }

    public function testACollectionTableIsKeyedByOwnerAndPosition(): void {
        $tags = self::carSchema()->getTable('car__chassis__tags');

        self::assertSame(['_owner', '_position', '_value'], self::columnNamesOf($tags));
        self::assertSame(['_owner', '_position'], $tags->getPrimaryKey()->getColumns());
    }

    public function testAnEmbedItemsStateTableFlagsEachItemColumn(): void {
        $state = self::carSchema()->getTable('_state__car__wheels');

        self::assertSame(['_owner', '_position', 'size', 'maker'], self::columnNamesOf($state));
    }

    public function testAFieldNameThatCouldLandOnAnEmbedsColumnIsRejectedWhenRead(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field name "a__b" cannot start with "_" or hold "__"');

        self::schemaOf(LayoutCollidingColumns::class);
    }

    public function testATableNameAlreadyTakenIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a table named "entities", which already exists');

        self::schemaOf(LayoutEntitiesTable::class);
    }

    public function testANameTooLongForMysqlIsRejected(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is longer than 64 characters');

        self::schemaOf(LayoutLongName::class);
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LayoutBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Values;
use XyloIsCoding\CoconutCms\Persistence\Migration\TableChanges;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\BlueprintDefaults;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\SchemaCompiler;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\ValueFlattener;

#[Entity(identifier: 'ed:vehicle', editorExtensible: true)]
class EdVehicle
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Scalar(ScalarType::Int)]
    public int $wheels = 4;

    #[Scalar(ScalarType::Int)]
    public int $price = 0;

    #[Scalar(ScalarType::String)]
    public string $color = 'white';
}

/** The vehicle with another default color. */
#[Entity(identifier: 'ed:vehicle', editorExtensible: true)]
class EdBlackVehicle
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Scalar(ScalarType::Int)]
    public int $wheels = 4;

    #[Scalar(ScalarType::Int)]
    public int $price = 0;

    #[Scalar(ScalarType::String)]
    public string $color = 'black';
}

/** The vehicle with its price renamed to cost. */
#[Entity(identifier: 'ed:vehicle', editorExtensible: true)]
class EdVehicleWithCost
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Scalar(ScalarType::Int)]
    public int $wheels = 4;

    #[Scalar(ScalarType::Int)]
    public int $cost = 0;

    #[Scalar(ScalarType::String)]
    public string $color = 'white';
}

/** The vehicle with a serial number added. */
#[Entity(identifier: 'ed:vehicle', editorExtensible: true)]
class EdVehicleWithSerial
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Scalar(ScalarType::Int)]
    public int $wheels = 4;

    #[Scalar(ScalarType::Int)]
    public int $price = 0;

    #[Scalar(ScalarType::String)]
    public string $color = 'white';

    #[Scalar(ScalarType::String)]
    public string $serial = '';
}

/** The vehicle with its price as text and its color as long text. */
#[Entity(identifier: 'ed:vehicle', editorExtensible: true)]
class EdVehicleRetyped
{
    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[Scalar(ScalarType::Int)]
    public int $wheels = 4;

    #[Scalar(ScalarType::String)]
    public string $price = '0 EUR';

    #[Scalar(ScalarType::Text)]
    public string $color = 'white';
}

/** Every existing vehicle gets the same serial. */
final class EdSerialInitializer implements FieldInitializer
{
    public function initialize(Values $values): Conversion {
        return Conversion::of('SN-1');
    }
}

/** An int price, as euros. */
final class EdPriceToEuros implements FieldConverter
{
    public function convert(Captured $old): Conversion {
        return Conversion::of($old->value() . ' EUR');
    }
}

/** A color, shouted. */
final class EdUpperCase implements FieldConverter
{
    public function convert(Captured $old): Conversion {
        return Conversion::of(strtoupper($old->value()));
    }
}

#[Entity(identifier: 'ed:engine')]
final class EdEngine
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;
}

/** The engine, renamed. */
#[Entity(identifier: 'ed:motor')]
final class EdMotor
{
    #[Scalar(ScalarType::Int)]
    public int $horsepower = 100;
}

/**
 * Native types an editor-created boat extends and owns, versions of them,
 * and the boat itself: created the way an editor save will create it, until
 * one exists.
 */
final class MigratorEditorTypes
{
    public const V1 = [Cms::class, EdVehicle::class, EdEngine::class];

    /**
     * An editor-created boat: a vehicle with a hull and an owned engine. It
     * sets its own wheels and price defaults, and inherits the rest.
     */
    public static function boat(): EntityBlueprint {
        return new EntityBlueprint(
            identifier: 'ed:boat',
            source: SchemaSource::Editor,
            parent: 'ed:vehicle',
            table: 'ed_boat',
            editorExtensible: false,
            fields: FieldDescriptorCollection::fromArray([
                'hull' => FieldDescriptor::scalar('hull', ScalarType::String, Nullability::NotNullable),
                'engine' => FieldDescriptor::owningReference('engine', 'ed:engine', Nullability::Nullable),
            ]),
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: [],
            prototypeValidators: [],
            defaults: BlueprintDefaults::own(FieldValues::fromArray(['wheels' => 0, 'price' => 5, 'hull' => 'wood', 'engine' => null])),
        );
    }

    /**
     * Compiles $blueprints and creates their tables, as an editor save will.
     * @param array<string, EntityBlueprint> $blueprints
     */
    public static function create(Connection $connection, SchemaStore $schemas, SchemaFieldValueStore $values, SchemaRegistry $registry, array $blueprints): void {
        (new SchemaCompiler($registry, $values, $schemas))->compile($blueprints);

        $layouts = new LayoutBuilder(new SchemaLookup($registry));
        (new TableChanges($connection))->expand(DatabaseSchema::of(array_map($layouts->levelOf(...), $schemas->identifiers())));
    }

    /**
     * Saves a new $identifier entity with $set touched and every other value
     * its default, the way a save writes values.
     * @param array<string, mixed> $set Keyed by path, in stored form.
     * @return string Its id.
     */
    public static function save(Connection $connection, SchemaFieldValueStore $values, SchemaRegistry $registry, string $identifier, array $set): string {
        $schemas = new SchemaLookup($registry);
        $defaults = (new ValueFlattener($schemas))->flatten($identifier, (new FieldValueResolver($registry, $values))->resolveTree(Subject::of($identifier)));
        $id = EntityIds::generate();
        EntityWriter::of($connection, $registry)->write(
            new EntityValues($identifier, FieldValues::fromArray([...$defaults->toArray(), ...$set]), FieldValues::fromArray($set)),
            $id,
            static fn (): string => EntityIds::generate(),
        );

        return $id;
    }
}

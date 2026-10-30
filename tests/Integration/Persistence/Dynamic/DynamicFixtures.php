<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic;

use XyloIsCoding\CoconutCms\Admin\AdminCode;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Module;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Setter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\AfterEdit;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\EditRefused;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\BlueprintDefaults;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/** Every afterEdit() call of the fixtures, in order: the class and the paths it was told. */
final class DyEdits
{
    /** @var list<array{string, list<string>}> */
    public static array $calls = [];
}

/** A craft: owns a motor and spare ones, may tow another craft. Editor-created types extend it. */
#[Entity(identifier: 'dy:craft', editorExtensible: true)]
class DyCraft implements AfterEdit
{
    public function afterEdit(array $paths): void {
        DyEdits::$calls[] = [self::class, $paths];
    }

    #[Scalar(ScalarType::String)]
    public string $name = '';

    #[OwningReference]
    public ?DyMotor $motor = null;

    /** @var list<DyMotor|null> */
    #[Collection(itemKind: FieldKind::OwningReference, itemTarget: DyMotor::class, itemNullability: Nullability::Nullable)]
    public array $spares = [];

    #[Reference]
    public ?DyCraft $tow = null;
}

/** A motor: its horsepower is set through a setter refusing a negative one; afterEdit() refuses 13. */
#[Entity(identifier: 'dy:motor', editorExtensible: true)]
class DyMotor implements AfterEdit
{
    public function afterEdit(array $paths): void {
        DyEdits::$calls[] = [self::class, $paths];
        if ($this->horsepower === 13) {
            throw new EditRefused('13 horsepower brings bad luck.');
        }
    }

    #[Scalar(ScalarType::Int)]
    #[Setter('setHorsepower')]
    public int $horsepower = 100;

    /** How many times setHorsepower() ran: an edit by path must go through it. */
    public static int $setterCalls = 0;

    public function setHorsepower(int $horsepower): void {
        self::$setterCalls++;
        if ($horsepower < 0) {
            throw new EditRefused('A motor can\'t have negative horsepower.');
        }

        $this->horsepower = $horsepower;
    }
}

/** A native motor for boats: the last native class of an editor-created outboard. */
#[Entity(identifier: 'dy:boat_motor', editorExtensible: true)]
class DyBoatMotor extends DyMotor
{
    #[Scalar(ScalarType::Int)]
    public int $propellers = 2;
}

/** The dynamic entity tests' native types, and the editor-created types built on them. */
final class DynamicFixtures
{
    public const CLASSES = [DyCraft::class, DyMotor::class, DyBoatMotor::class];

    /**
     * Every editor-created type, by identifier: a yacht extending the craft,
     * owning a dinghy's motor on its own level; an outboard extending the boat
     * motor; and a buoy with no native ancestor at all.
     * @return array<string, EntityBlueprint>
     */
    public static function editorTypes(): array {
        return [
            'dy:yacht' => self::blueprint('dy:yacht', 'dy:craft', 'dy_yacht', [
                'cabins' => FieldDescriptor::scalar('cabins', ScalarType::Int, Nullability::NotNullable),
                'dinghy' => FieldDescriptor::owningReference('dinghy', 'dy:motor', Nullability::Nullable),
            ], ['name' => 'Yacht', 'cabins' => 3, 'dinghy' => null]),
            // It sets the native horsepower's default too: the view must show it.
            'dy:outboard' => self::blueprint('dy:outboard', 'dy:boat_motor', 'dy_outboard', ['fuel' => FieldDescriptor::scalar('fuel', ScalarType::String, Nullability::NotNullable)], ['fuel' => 'petrol', 'horsepower' => 300]),
            'dy:buoy' => self::blueprint('dy:buoy', null, 'dy_buoy', ['color' => FieldDescriptor::scalar('color', ScalarType::String, Nullability::NotNullable)], ['color' => 'red']),
        ];
    }

    /**
     * An editor-created type extending $parent with $fields, setting $defaults itself.
     * @param array<string, FieldDescriptor> $fields
     * @param array<string, mixed> $defaults
     */
    private static function blueprint(string $identifier, ?string $parent, string $table, array $fields, array $defaults): EntityBlueprint {
        return new EntityBlueprint(
            identifier: $identifier,
            source: SchemaSource::Editor,
            parent: $parent,
            table: $table,
            editorExtensible: true,
            fields: FieldDescriptorCollection::fromArray($fields),
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: [],
            prototypeValidators: [],
            defaults: BlueprintDefaults::own(FieldValues::fromArray($defaults)),
        );
    }
}

final class DynamicModule implements Module
{
    public function name(): string {
        return 'dynamic';
    }

    public function classes(): array {
        return DynamicFixtures::CLASSES;
    }

    public function steps(): MigrationSteps {
        return MigrationSteps::none();
    }

    public function operations(): array {
        return [];
    }

    public function adminCode(): ?AdminCode {
        return null;
    }
}

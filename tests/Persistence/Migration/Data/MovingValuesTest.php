<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\LevelInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\MigratingValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Transformation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValueMark;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Values;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\DropField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\DropType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MakeNotNullable;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MoveValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Reparent;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Transform;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationPlan;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationPlanner;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;

/** Writes a price as text, and leaves a price that followed its default following the new one. */
final class MovingPriceToText implements FieldConverter
{
    use ConvertsAnyField;

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        return $old->isDefault() ? Conversion::default() : Conversion::of(sprintf('%d EUR', $old->value()));
    }
}

/** Records what it's given, and turns a chassis into its weight. */
final class MovingChassisToWeight implements FieldConverter
{
    use ConvertsAnyField;

    /** @var list<array{mixed, bool, list<string>}> */
    public array $captured = [];

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        $this->captured[] = [$old->value(), $old->isDefault(), $old->under()->paths()];

        return Conversion::of($old->under()->get('weight'));
    }
}

/** A new rating that starts from the car's price. */
final class MovingRatingFromPrice implements FieldInitializer
{
    use FillsAnyField;

    public function initialize(Values $values): Conversion {
        return Conversion::of($values->get('price') > 100 ? 5 : 1);
    }
}

/** Fills the vehicle level a car gains, and, when asked, tries to touch the car's own price too. */
final class MovingVehicleFromCar implements LevelInitializer
{
    public function __construct(
        private readonly bool $alsoTouchesPrice = false,
    ) {
    }

    public function initialize(MigratingValues $values): void {
        $values->set('doors', Conversion::of(4));
        if ($this->alsoTouchesPrice) {
            $values->set('price', Conversion::of(1));
        }
    }
}

/** Splits a full name into a first and a last name. */
final class MovingSplitName implements Transformation
{
    public function declares(): array {
        return [new DropField('test:car', 'owner'), new AddField('test:car', 'first'), new AddField('test:car', 'last')];
    }

    public function transform(MigratingValues $values): void {
        [$first, $last] = explode(' ', $values->get('owner'), 2);
        $values->set('first', Conversion::of($first));
        $values->set('last', Conversion::of($last));
        $values->remove('owner');
    }
}

final class MovingValuesTest extends TestCase
{
    /** @param array<string, FieldDescriptor> $fields */
    private static function schema(string $identifier, array $fields = [], ?string $parent = null): EntitySchema {
        return new EntitySchema(
            identifier: $identifier,
            parent: $parent,
            table: str_replace(':', '_', $identifier),
            editorExtensible: false,
            fields: FieldDescriptorCollection::fromArray($fields),
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: [],
            prototypeValidators: [],
        );
    }

    private static function int(string $name): FieldDescriptor {
        return FieldDescriptor::scalar($name, ScalarType::Int, Nullability::Nullable);
    }

    /** @return array<string, FieldDescriptor> A car's own fields: a chassis Embed, an owned engine, Embed wheels, owned spares. */
    private static function carFields(): array {
        return [
            'price' => self::int('price'),
            'chassis' => FieldDescriptor::embed('chassis', 'test:chassis'),
            'engine' => FieldDescriptor::owningReference('engine', 'test:engine', Nullability::Nullable),
            'wheels' => FieldDescriptor::collection('wheels', FieldDescriptor::embed('wheels', 'test:wheel')),
            'spares' => FieldDescriptor::collection('spares', FieldDescriptor::owningReference('spares', 'test:engine', Nullability::Nullable)),
        ];
    }

    /**
     * Every type a car needs, with the car's own fields as given.
     * @param array<string, FieldDescriptor> $car
     * @return array<string, EntitySchema> Keyed by identifier.
     */
    private static function types(array $car, ?string $carParent = null, EntitySchema ...$others): array {
        $types = [
            self::schema('test:chassis', ['weight' => self::int('weight')]),
            self::schema('test:wheel', ['size' => self::int('size')]),
            self::schema('test:engine', ['horsepower' => self::int('horsepower')]),
            self::schema('test:turbo_engine', ['boost' => self::int('boost')], parent: 'test:engine'),
            self::schema('test:car', $car, parent: $carParent),
            ...$others,
        ];

        return array_column(array_map(static fn (EntitySchema $schema): array => [$schema->identifier, $schema], $types), 1, 0);
    }

    /**
     * @param array<string, EntitySchema> $stored
     * @param array<string, EntitySchema> $code
     */
    private static function plan(array $stored, array $code, Fixup ...$fixups): MigrationPlan {
        return (new MigrationPlanner())->plan(
            array_values(array_map(static fn (EntitySchema $schema): StoredSchema => new StoredSchema($schema, SchemaSource::Native, str_repeat('0', 64), []), $stored)),
            $code,
            MigrationSteps::none()->add('step', $fixups),
        );
    }

    /**
     * @param array<string, mixed> $touched
     * @param array<string, mixed> $untouched
     * @param array<string, string> $ownedIds
     */
    private static function aggregate(array $touched, array $untouched = [], array $ownedIds = [], string $identifier = 'test:car'): AggregateValues {
        return new AggregateValues(
            $identifier,
            [...$untouched, ...$touched],
            [...array_map(static fn (): ValueMark => ValueMark::Untouched, $untouched), ...array_map(static fn (): ValueMark => ValueMark::Touched, $touched)],
            $ownedIds,
        );
    }

    /** @return array<string, array{mixed, string}> Each value with its mark's name, sorted by path. */
    private static function valuesOf(AggregateValues $values): array {
        $out = [];
        foreach ($values->values() as $path => $value) {
            $out[$path] = [$value, $values->marks()[$path]->name];
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    public function testRenamingAFieldMovesItsValueAndItsMarkWhereverItsTypeLives(): void {
        $engine = self::schema('test:engine', ['power' => self::int('power')]);
        $plan = self::plan(
            self::types(self::carFields()),
            [...self::types(self::carFields()), 'test:engine' => $engine],
            new RenameField('test:engine', 'horsepower', 'power'),
        );
        $car = self::aggregate(
            ['engine.horsepower' => 450, 'spares' => 2, 'spares.0' => 'test:engine', 'spares.1' => 'test:turbo_engine', 'spares.1.horsepower' => 600],
            ['engine' => 'test:engine', 'spares.0.horsepower' => 300],
        );

        $plan->moveValues($car);

        self::assertSame([
            'engine' => ['test:engine', 'Untouched'],
            'engine.power' => [450, 'Touched'],
            'spares' => [2, 'Touched'],
            'spares.0' => ['test:engine', 'Touched'],
            'spares.0.power' => [300, 'Untouched'],
            'spares.1' => ['test:turbo_engine', 'Touched'],
            'spares.1.power' => [600, 'Touched'],
        ], self::valuesOf($car));
    }

    public function testRenamingAnEmbedTargetsFieldReachesEveryEmbedSite(): void {
        $plan = self::plan(
            self::types(self::carFields()),
            [...self::types(self::carFields()), 'test:wheel' => self::schema('test:wheel', ['diameter' => self::int('diameter')]), 'test:chassis' => self::schema('test:chassis', ['mass' => self::int('mass')])],
            new RenameField('test:wheel', 'size', 'diameter'),
            new RenameField('test:chassis', 'weight', 'mass'),
        );
        $car = self::aggregate(['chassis:weight' => 1200, 'wheels' => 2, 'wheels.0.size' => 17, 'wheels.1.size' => 19]);

        $plan->moveValues($car);

        self::assertSame(
            ['chassis:mass' => [1200, 'Touched'], 'wheels' => [2, 'Touched'], 'wheels.0.diameter' => [17, 'Touched'], 'wheels.1.diameter' => [19, 'Touched']],
            self::valuesOf($car),
        );
    }

    public function testAConverterSeesTouchedAndDefaultValuesAndDecidesForBoth(): void {
        $fields = ['price' => FieldDescriptor::scalar('price', ScalarType::String, Nullability::Nullable)] + self::carFields();
        $plan = self::plan(self::types(self::carFields()), self::types($fields), RetypeField::converting('test:car', 'price', new MovingPriceToText()));

        $touched = self::aggregate(['price' => 150]);
        $plan->moveValues($touched);
        self::assertSame(['price' => ['150 EUR', 'Converted']], self::valuesOf($touched));

        $untouched = self::aggregate([], ['price' => 100]);
        $plan->moveValues($untouched);
        self::assertSame([], self::valuesOf($untouched));
    }

    public function testAnEmbedsCaptureIsWhatsUnderIt(): void {
        $fields = ['chassis' => self::int('chassis')] + self::carFields();
        $converter = new MovingChassisToWeight();
        $plan = self::plan(self::types(self::carFields()), self::types($fields), RetypeField::converting('test:car', 'chassis', $converter));
        $car = self::aggregate(['chassis:weight' => 1200]);

        $plan->moveValues($car);

        self::assertSame([[null, false, ['weight']]], $converter->captured);
        self::assertSame(['chassis' => [1200, 'Converted']], self::valuesOf($car));
    }

    public function testResetDropsEveryValueOfTheField(): void {
        $fields = ['price' => FieldDescriptor::scalar('price', ScalarType::String, Nullability::Nullable)] + self::carFields();
        $plan = self::plan(self::types(self::carFields()), self::types($fields), RetypeField::reset('test:car', 'price'));
        $car = self::aggregate(['price' => 150, 'chassis:weight' => 1200]);

        $plan->moveValues($car);

        self::assertSame(['chassis:weight' => [1200, 'Touched']], self::valuesOf($car));
    }

    public function testMovingValuesAcrossAnOwnedBoundaryKeepsTheOwnedId(): void {
        $plan = self::plan(self::types(self::carFields()), self::types(self::carFields()), new MoveValues('test:car', 'spares.0', 'engine'));
        $car = self::aggregate(['spares' => 1, 'spares.0' => 'test:turbo_engine', 'spares.0.boost' => 9], [], ['spares.0' => 'spare-id', 'engine' => 'engine-id']);

        $plan->moveValues($car);

        self::assertSame(['engine' => ['test:turbo_engine', 'Touched'], 'engine.boost' => [9, 'Touched'], 'spares' => [1, 'Touched']], self::valuesOf($car));
        self::assertSame(['engine' => 'spare-id'], $car->ownedIds());
    }

    public function testRenamingATypeRenamesEverySlotHoldingItAndTheRoot(): void {
        $renamed = [
            'test:chassis' => self::schema('test:chassis', ['weight' => self::int('weight')]),
            'test:wheel' => self::schema('test:wheel', ['size' => self::int('size')]),
            'test:motor' => self::schema('test:motor', ['horsepower' => self::int('horsepower')]),
            'test:turbo_engine' => self::schema('test:turbo_engine', ['boost' => self::int('boost')], parent: 'test:motor'),
            'test:car' => self::schema('test:car', [
                'price' => self::int('price'),
                'chassis' => FieldDescriptor::embed('chassis', 'test:chassis'),
                'engine' => FieldDescriptor::owningReference('engine', 'test:motor', Nullability::Nullable),
                'wheels' => FieldDescriptor::collection('wheels', FieldDescriptor::embed('wheels', 'test:wheel')),
                'spares' => FieldDescriptor::collection('spares', FieldDescriptor::owningReference('spares', 'test:motor', Nullability::Nullable)),
            ]),
        ];
        $plan = self::plan(self::types(self::carFields()), $renamed, new RenameType('test:engine', 'test:motor'));
        $car = self::aggregate(['spares' => 1, 'spares.0' => 'test:engine'], ['engine' => 'test:engine']);

        $plan->moveValues($car);

        self::assertSame(['engine' => ['test:motor', 'Untouched'], 'spares' => [1, 'Touched'], 'spares.0' => ['test:motor', 'Touched']], self::valuesOf($car));
    }

    public function testDroppingATypeEmptiesEverySlotHoldingIt(): void {
        $types = self::types(self::carFields());
        unset($types['test:turbo_engine']);
        $plan = self::plan(self::types(self::carFields()), $types, new DropType('test:turbo_engine'));
        $car = self::aggregate(
            ['engine' => 'test:turbo_engine', 'engine.boost' => 9, 'spares' => 2, 'spares.0' => 'test:engine', 'spares.1' => 'test:turbo_engine'],
            [],
            ['engine' => 'engine-id', 'spares.0' => 'spare-id', 'spares.1' => 'turbo-id'],
        );

        $plan->moveValues($car);

        self::assertSame(
            ['engine' => [null, 'Converted'], 'spares' => [2, 'Touched'], 'spares.0' => ['test:engine', 'Touched'], 'spares.1' => [null, 'Converted']],
            self::valuesOf($car),
        );
        self::assertSame(['spares.0' => 'spare-id'], $car->ownedIds());
    }

    public function testMakingAFieldNotNullableUntouchesItsNullsAndKeepsEveryOtherValue(): void {
        $fields = ['price' => FieldDescriptor::scalar('price', ScalarType::Int, Nullability::NotNullable)] + self::carFields();
        $plan = self::plan(self::types(self::carFields()), self::types($fields), new MakeNotNullable('test:car', 'price'));

        $nulled = self::aggregate(['price' => null, 'chassis:weight' => 1200]);
        $plan->moveValues($nulled);
        self::assertSame(['chassis:weight' => [1200, 'Touched']], self::valuesOf($nulled));

        $priced = self::aggregate(['price' => 150]);
        $plan->moveValues($priced);
        self::assertSame(['price' => [150, 'Touched']], self::valuesOf($priced));
    }

    public function testMakingItemsNotNullableRemovesTheNullOnesAndMovesTheRestUp(): void {
        $fields = ['spares' => FieldDescriptor::collection('spares', FieldDescriptor::owningReference('spares', 'test:engine', Nullability::NotNullable))] + self::carFields();
        $plan = self::plan(self::types(self::carFields()), self::types($fields), new MakeNotNullable('test:car', 'spares'));
        $car = self::aggregate(
            ['spares' => 3, 'spares.0' => null, 'spares.1' => 'test:engine', 'spares.1.horsepower' => 200, 'spares.2' => 'test:turbo_engine'],
            [],
            ['spares.1' => 'first-id', 'spares.2' => 'second-id'],
        );

        $plan->moveValues($car);

        self::assertSame(
            ['spares' => [2, 'Touched'], 'spares.0' => ['test:engine', 'Touched'], 'spares.0.horsepower' => [200, 'Touched'], 'spares.1' => ['test:turbo_engine', 'Touched']],
            self::valuesOf($car),
        );
        self::assertSame(['spares.0' => 'first-id', 'spares.1' => 'second-id'], $car->ownedIds());
    }

    public function testAnInitializerFillsAnAddedFieldFromWhatsThere(): void {
        $fields = self::carFields() + ['rating' => self::int('rating')];
        $plan = self::plan(self::types(self::carFields()), self::types($fields), new AddField('test:car', 'rating', new MovingRatingFromPrice()));
        $car = self::aggregate(['price' => 150]);

        $plan->moveValues($car);

        self::assertSame(['price' => [150, 'Touched'], 'rating' => [5, 'Converted']], self::valuesOf($car));
    }

    public function testALevelInitializerFillsTheLevelsGainedAndNothingElse(): void {
        $vehicle = self::schema('test:vehicle', ['doors' => self::int('doors')]);
        $stored = self::types(self::carFields(), null, $vehicle);
        $code = self::types(self::carFields(), 'test:vehicle', $vehicle);

        $car = self::aggregate(['price' => 150]);
        self::plan($stored, $code, new Reparent('test:car', new MovingVehicleFromCar()))->moveValues($car);
        self::assertSame(['doors' => [4, 'Converted'], 'price' => [150, 'Touched']], self::valuesOf($car));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"price" may not be changed here: only doors may.');
        self::plan($stored, $code, new Reparent('test:car', new MovingVehicleFromCar(alsoTouchesPrice: true)))->moveValues(self::aggregate(['price' => 150]));
    }

    public function testATransformationRewritesWhatNoShorthandCan(): void {
        $stored = self::types(self::carFields() + ['owner' => FieldDescriptor::scalar('owner', ScalarType::String, Nullability::Nullable)]);
        $code = self::types(self::carFields() + ['first' => FieldDescriptor::scalar('first', ScalarType::String, Nullability::Nullable), 'last' => FieldDescriptor::scalar('last', ScalarType::String, Nullability::Nullable)]);
        $plan = self::plan($stored, $code, new Transform('test:car', new MovingSplitName()));
        $car = self::aggregate(['owner' => 'Ada Lovelace']);

        $plan->moveValues($car);

        self::assertSame(['first' => ['Ada', 'Converted'], 'last' => ['Lovelace', 'Converted']], self::valuesOf($car));
    }

    public function testATypeNestedInItselfIsRenamedDeepestFirst(): void {
        $stored = ['test:node' => self::schema('test:node', ['child' => FieldDescriptor::owningReference('child', 'test:node', Nullability::Nullable), 'label' => self::int('label')])];
        $code = ['test:node' => self::schema('test:node', ['next' => FieldDescriptor::owningReference('next', 'test:node', Nullability::Nullable), 'label' => self::int('label')])];
        $plan = self::plan($stored, $code, new RenameField('test:node', 'child', 'next'));
        $node = self::aggregate(['child' => 'test:node', 'child.child' => 'test:node', 'child.child.label' => 3], [], ['child' => 'a', 'child.child' => 'b'], 'test:node');

        $plan->moveValues($node);

        self::assertSame(['next' => ['test:node', 'Touched'], 'next.next' => ['test:node', 'Touched'], 'next.next.label' => [3, 'Touched']], self::valuesOf($node));
        self::assertSame(['next' => 'a', 'next.next' => 'b'], $node->ownedIds());
    }
}

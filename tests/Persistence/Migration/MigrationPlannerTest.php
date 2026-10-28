<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MakeNotNullable;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\DropField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\DropType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MoveTable;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MoveValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Reparent;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationBlocked;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationPlanner;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Migration\PlannedFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\DeveloperPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\UnsafePolicy;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;

final class MigrationPlannerTest extends TestCase
{
    /** @param array<string, FieldDescriptor> $fields */
    private static function schema(string $identifier, array $fields = [], ?string $parent = null, ?string $table = null, bool $editorExtensible = false): EntitySchema {
        return new EntitySchema(
            identifier: $identifier,
            parent: $parent,
            table: $table ?? str_replace(':', '_', $identifier),
            editorExtensible: $editorExtensible,
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

    private static function stored(EntitySchema $schema, SchemaSource $source = SchemaSource::Native): StoredSchema {
        return new StoredSchema($schema, $source, str_repeat('0', 64), []);
    }

    /** @param list<EntitySchema> $schemas */
    private static function code(EntitySchema ...$schemas): array {
        return array_column(array_map(static fn (EntitySchema $schema): array => [$schema->identifier, $schema], $schemas), 1, 0);
    }

    /**
     * @param list<PlannedFixup> $fixups
     * @return list<string>
     */
    private static function described(array $fixups): array {
        return array_map(static fn (PlannedFixup $planned): string => $planned->fixup->describe(), $fixups);
    }

    public function testNothingChangedPlansNothing(): void {
        $car = self::schema('test:car', ['price' => self::int('price')]);

        self::assertTrue((new MigrationPlanner())->plan([self::stored($car)], self::code($car), MigrationSteps::none())->isEmpty());
    }

    public function testStrictLetsAnAdditionThroughAsItsOwnFixup(): void {
        $plan = (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:car'))],
            self::code(self::schema('test:car', ['price' => self::int('price')]), self::schema('test:bike')),
            MigrationSteps::none(),
        );

        self::assertSame(['AddField(test:car, price)', 'AddType(test:bike)'], self::described($plan->fixups));
    }

    public function testStrictBlocksAnythingUndeclaredButAnAdditionNamingEachDifference(): void {
        try {
            (new MigrationPlanner())->plan(
                [self::stored(self::schema('test:car', ['price' => self::int('price')])), self::stored(self::schema('test:bike'))],
                self::code(self::schema('test:car')),
                MigrationSteps::none(),
            );
            self::fail('The migration should have been blocked.');
        } catch (MigrationBlocked $blocked) {
            self::assertStringContainsString('Field "price" was removed from type "test:car".', $blocked->getMessage());
            self::assertStringContainsString('Type "test:bike" was removed.', $blocked->getMessage());
        }
    }

    public function testADeclaredFixupExplainsItsDifference(): void {
        $plan = (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:car', ['price' => self::int('price')]))],
            self::code(self::schema('test:car')),
            MigrationSteps::none()->add('drop-price', [new DropField('test:car', 'price')]),
        );

        self::assertSame(['drop-price'], $plan->stepIds);
        self::assertSame(['DropField(test:car, price)'], self::described($plan->fixups));
    }

    public function testARenameIsNeverMistakenForADropAndAnAdd(): void {
        $plan = (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:car', ['price' => self::int('price')]))],
            self::code(self::schema('test:car', ['cost' => self::int('cost')])),
            MigrationSteps::none()->add('rename-price', [new RenameField('test:car', 'price', 'cost')]),
        );

        self::assertSame(['RenameField(test:car, price → cost)'], self::described($plan->fixups));
    }

    public function testStepsRunInOrderSoChainedRenamesCompose(): void {
        $plan = (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:car', ['price' => self::int('price')]))],
            self::code(self::schema('test:car', ['amount' => self::int('amount')])),
            MigrationSteps::none()
                ->add('price-to-cost', [new RenameField('test:car', 'price', 'cost')])
                ->add('cost-to-amount', [new RenameField('test:car', 'cost', 'amount')]),
        );

        self::assertSame(['price-to-cost', 'cost-to-amount'], $plan->stepIds);
    }

    public function testOnlyPendingStepsRun(): void {
        $steps = MigrationSteps::none()
            ->add('price-to-cost', [new RenameField('test:car', 'price', 'cost')])
            ->add('cost-to-amount', [new RenameField('test:car', 'cost', 'amount')]);

        $plan = (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:car', ['cost' => self::int('cost')]))],
            self::code(self::schema('test:car', ['amount' => self::int('amount')])),
            $steps->pending(['price-to-cost']),
        );

        self::assertSame(['cost-to-amount'], $plan->stepIds);
    }

    public function testTwoStepsCanNeverShareAnId(): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Two migration steps have the id "rename-price".');

        MigrationSteps::none()
            ->add('rename-price', [new RenameField('test:car', 'price', 'cost')])
            ->add('rename-price', [new RenameField('test:car', 'cost', 'amount')]);
    }

    public function testAFixupNamingWhatDoesNotExistThrowsNamingItsStep(): void {
        $this->expectException(InvalidFixup::class);
        $this->expectExceptionMessage('Step "typo", RenameField(test:car, prize → cost): type "test:car" has no field "prize" at this point.');

        (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:car', ['price' => self::int('price')]))],
            self::code(self::schema('test:car', ['cost' => self::int('cost')])),
            MigrationSteps::none()->add('typo', [new RenameField('test:car', 'prize', 'cost')]),
        );
    }

    public function testRenamingATypeRetargetsEverySchemaNamingItAndTakesTheCodesTable(): void {
        $stored = [
            self::stored(self::schema('test:car', table: 'car', editorExtensible: true)),
            self::stored(self::schema('test:garage', ['cars' => FieldDescriptor::collection('cars', FieldDescriptor::reference('cars', 'test:car'))])),
            self::stored(self::schema('editor:race_car', parent: 'test:car'), SchemaSource::Editor),
        ];
        $code = self::code(
            self::schema('test:automobile', table: 'automobile', editorExtensible: true),
            self::schema('test:garage', ['cars' => FieldDescriptor::collection('cars', FieldDescriptor::reference('cars', 'test:automobile'))]),
        );

        $plan = (new MigrationPlanner())->plan($stored, $code, MigrationSteps::none()->add('rename-car', [new RenameType('test:car', 'test:automobile')]));

        // The garage's target and the table both followed: nothing else to declare.
        self::assertSame(['RenameType(test:car → test:automobile)'], self::described($plan->fixups));
    }

    public function testAnEditorCreatedTypeIsNeverARemovedNativeOne(): void {
        $car = self::schema('test:car');

        $plan = (new MigrationPlanner())->plan([self::stored($car), self::stored(self::schema('editor:race_car'), SchemaSource::Editor)], self::code($car), MigrationSteps::none());

        self::assertTrue($plan->isEmpty());
    }

    public function testResetExplainsAChangedField(): void {
        $plan = (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:car', ['speed' => self::int('speed')]))],
            self::code(self::schema('test:car', ['speed' => FieldDescriptor::scalar('speed', ScalarType::Float, Nullability::Nullable)])),
            MigrationSteps::none()->add('reset-speed', [RetypeField::reset('test:car', 'speed')]),
        );

        self::assertSame(['RetypeField::reset(test:car, speed)'], self::described($plan->fixups));
    }

    public function testDeveloperLetsLosslessChangesThroughButNotARemovedLevel(): void {
        $stored = [self::stored(self::schema('test:vehicle')), self::stored(self::schema('test:car', parent: 'test:vehicle', table: 'cars'))];

        $inserted = (new MigrationPlanner(new DeveloperPolicy()))->plan(
            $stored,
            self::code(self::schema('test:vehicle'), self::schema('test:motor_vehicle', parent: 'test:vehicle'), self::schema('test:car', parent: 'test:motor_vehicle', table: 'car')),
            MigrationSteps::none(),
        );
        self::assertSame(['AddType(test:motor_vehicle)', 'Reparent(test:car)', 'MoveTable(test:car)'], self::described($inserted->fixups));

        $this->expectException(MigrationBlocked::class);
        (new MigrationPlanner(new DeveloperPolicy()))->plan($stored, self::code(self::schema('test:vehicle'), self::schema('test:car', table: 'cars')), MigrationSteps::none());
    }

    public function testUnsafeResolvesEverythingByDroppingAndResetting(): void {
        $plan = (new MigrationPlanner(new UnsafePolicy()))->plan(
            [self::stored(self::schema('test:car', ['price' => self::int('price'), 'speed' => self::int('speed')])), self::stored(self::schema('test:bike'))],
            self::code(self::schema('test:car', ['cost' => self::int('cost'), 'speed' => FieldDescriptor::scalar('speed', ScalarType::Float, Nullability::Nullable)])),
            MigrationSteps::none(),
        );

        self::assertSame(
            ['AddField(test:car, cost)', 'RetypeField::reset(test:car, speed)', 'DropField(test:car, price)', 'DropType(test:bike)'],
            self::described($plan->fixups),
        );
    }

    public function testOnlyUnsafeTakesNullsAwayFromAFieldWithoutAStep(): void {
        $stored = [self::stored(self::schema('test:car', ['speed' => self::int('speed')]))];
        $code = self::code(self::schema('test:car', ['speed' => FieldDescriptor::scalar('speed', ScalarType::Int, Nullability::NotNullable)]));

        $unsafe = (new MigrationPlanner(new UnsafePolicy()))->plan($stored, $code, MigrationSteps::none());
        self::assertSame(['MakeNotNullable(test:car, speed)'], self::described($unsafe->fixups));

        $declared = (new MigrationPlanner())->plan($stored, $code, MigrationSteps::none()->add('speed-required', [new MakeNotNullable('test:car', 'speed')]));
        self::assertSame(['MakeNotNullable(test:car, speed)'], self::described($declared->fixups));

        $this->expectException(MigrationBlocked::class);
        (new MigrationPlanner(new DeveloperPolicy()))->plan($stored, $code, MigrationSteps::none());
    }

    public function testMakeNotNullableNamesAFieldThatCanHoldNull(): void {
        $this->expectException(InvalidFixup::class);
        $this->expectExceptionMessage('field "speed" of type "test:car" can\'t hold null at this point.');

        (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:car', ['speed' => FieldDescriptor::scalar('speed', ScalarType::Int, Nullability::NotNullable)]))],
            self::code(self::schema('test:car', ['speed' => FieldDescriptor::scalar('speed', ScalarType::Int, Nullability::NotNullable)])),
            MigrationSteps::none()->add('speed-required', [new MakeNotNullable('test:car', 'speed')]),
        );
    }

    public function testADeclaredAdditionIsTakenAsIsNotAddedTwice(): void {
        $plan = (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:car'))],
            self::code(self::schema('test:car', ['price' => self::int('price')])),
            MigrationSteps::none()->add('add-price', [new AddField('test:car', 'price')]),
        );

        self::assertSame(['AddField(test:car, price)'], self::described($plan->fixups));
    }

    public function testDroppingATypeThenDeclaringItAgainIsCaught(): void {
        $this->expectException(InvalidFixup::class);
        $this->expectExceptionMessage('there is no type "test:car" at this point');

        (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:car'))],
            self::code(),
            MigrationSteps::none()->add('drop', [new DropType('test:car'), new MoveTable('test:car')]),
        );
    }

    public function testMoveFieldExplainsADeclarationMovingUpItsChain(): void {
        $plan = (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:vehicle')), self::stored(self::schema('test:car', ['price' => self::int('price')], parent: 'test:vehicle'))],
            self::code(self::schema('test:vehicle', ['price' => self::int('price')]), self::schema('test:car', parent: 'test:vehicle')),
            MigrationSteps::none()->add('price-up', [new MoveField('test:car', 'price', 'test:vehicle')]),
        );

        self::assertSame(['MoveField(price, test:car → test:vehicle)'], self::described($plan->fixups));
    }

    public function testMoveFieldOutsideOneChainIsRejected(): void {
        $this->expectException(InvalidFixup::class);
        $this->expectExceptionMessage('are not in one chain');

        (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:bike')), self::stored(self::schema('test:car', ['price' => self::int('price')]))],
            self::code(self::schema('test:bike', ['price' => self::int('price')]), self::schema('test:car')),
            MigrationSteps::none()->add('price-to-bike', [new MoveField('test:car', 'price', 'test:bike')]),
        );
    }

    public function testMoveValuesChangesNoSchemaSoTheDropStillNeedsDeclaring(): void {
        $stored = [self::stored(self::schema('test:car', ['price' => self::int('price')]))];
        $code = self::code(self::schema('test:car', ['cost' => self::int('cost')]));

        try {
            (new MigrationPlanner())->plan($stored, $code, MigrationSteps::none()->add('move', [new AddField('test:car', 'cost'), new MoveValues('test:car', 'price', 'cost')]));
            self::fail('Moving values alone should not explain the removed field.');
        } catch (MigrationBlocked $blocked) {
            self::assertStringContainsString('Field "price" was removed from type "test:car".', $blocked->getMessage());
        }

        $plan = (new MigrationPlanner())->plan($stored, $code, MigrationSteps::none()->add('move', [
            new AddField('test:car', 'cost'),
            new MoveValues('test:car', 'price', 'cost'),
            new DropField('test:car', 'price'),
        ]));
        self::assertSame(['AddField(test:car, cost)', 'MoveValues(test:car, price → cost)', 'DropField(test:car, price)'], self::described($plan->fixups));
    }

    public function testMoveValuesNeedsBothPathsToExistWhenItRuns(): void {
        $stored = [self::stored(self::schema('test:car', ['price' => self::int('price')]))];
        $code = self::code(self::schema('test:car', ['price' => self::int('price'), 'cost' => self::int('cost')]));

        $this->expectException(InvalidFixup::class);
        $this->expectExceptionMessage('Step "move", MoveValues(test:car, price → cost): type "test:car" has no path "cost" at this point.');

        (new MigrationPlanner())->plan($stored, $code, MigrationSteps::none()->add('move', [new MoveValues('test:car', 'price', 'cost')]));
    }

    public function testReparentTakesTheCodesParent(): void {
        $plan = (new MigrationPlanner())->plan(
            [self::stored(self::schema('test:vehicle')), self::stored(self::schema('test:car'))],
            self::code(self::schema('test:vehicle'), self::schema('test:car', parent: 'test:vehicle')),
            MigrationSteps::none()->add('car-is-a-vehicle', [new Reparent('test:car')]),
        );

        self::assertSame(['Reparent(test:car)'], self::described($plan->fixups));
    }
}

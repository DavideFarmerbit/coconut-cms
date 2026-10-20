<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Registry;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\DefaultValues\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\DefaultValues\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StaleSchemaException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Tests\Persistence\Schema\InMemorySchemaStores;

#[Entity]
final class RegistryPiston
{
    #[Scalar(ScalarType::Int)]
    private int $diameter = 10;
}

#[Entity]
final class RegistryEngine
{
    #[Embed]
    private RegistryPiston $piston;

    public function __construct() {
        $this->piston = new RegistryPiston();
    }
}

#[Entity]
final class RegistryOwner
{
    #[OwningReference]
    private ?RegistryPiston $piston = null;
}

#[Entity]
class RegistryBase
{
    #[Scalar(ScalarType::Int)]
    private int $value = 1;
}

#[Entity]
class RegistryMiddle extends RegistryBase
{
}

#[Entity]
final class RegistryLeaf extends RegistryMiddle
{
}

#[Entity]
final class RegistryCycleA
{
    #[OwningReference]
    private ?RegistryCycleB $b = null;
}

#[Entity]
final class RegistryCycleB
{
    #[OwningReference]
    private ?RegistryCycleA $a = null;
}

final class SchemaRegistryTest extends TestCase
{
    private static function emptySchema(string $identifier): EntitySchema {
        return new EntitySchema(
            identifier: $identifier,
            parent: null,
            table: 'whatever',
            editorExtensible: false,
            fields: FieldDescriptorCollection::empty(),
            fieldPermissions: FieldPermissionCollection::empty(),
            fieldValidators: FieldValidatorCollection::empty(),
            uniqueGroups: [],
            prototypeValidators: [],
        );
    }

    /**
     * @param class-string[] $classes
     * @return array<string, EntityBlueprint>
     */
    private static function blueprintsOf(array $classes): array {
        $blueprints = [];
        foreach ($classes as $class) {
            $blueprints[$class] = NativeEntityReader::read($class);
        }

        return $blueprints;
    }

    /**
     * $class's blueprint with its default values replaced: how a test stands
     * in for a class changing between two compiles.
     * @return array<string, EntityBlueprint>
     */
    private static function changed(string $class, ObjectNode $defaultValues): array {
        $original = NativeEntityReader::read($class);

        return [$class => new EntityBlueprint(
            identifier: $original->identifier,
            parent: $original->parent,
            table: $original->table,
            editorExtensible: $original->editorExtensible,
            fields: $original->fields,
            fieldPermissions: $original->fieldPermissions,
            fieldValidators: $original->fieldValidators,
            uniqueGroups: $original->uniqueGroups,
            prototypeValidators: $original->prototypeValidators,
            defaultValues: $defaultValues,
        )];
    }

    /** A registry with nothing in memory: everything it serves comes from the store. */
    private static function freshRegistry(InMemorySchemaStores $stores): SchemaRegistry {
        return new SchemaRegistry($stores->schemas);
    }

    private static function assertStaleBecauseOf(string $dependency, callable $get): void {
        try {
            $get();
            self::fail('Expected a StaleSchemaException.');
        } catch (StaleSchemaException $exception) {
            self::assertSame($dependency, $exception->dependency);
        }
    }

    public function testHasIsFalseForAnIdentifierNeverRegistered(): void {
        $registry = (new InMemorySchemaStores())->registry;

        self::assertFalse($registry->has('Never\\Registered'));
    }

    public function testRegisterThenHasAndGetFindTheSameSchema(): void {
        $registry = (new InMemorySchemaStores())->registry;
        $schema = self::emptySchema('App\\Product');

        $registry->register($schema);

        self::assertTrue($registry->has('App\\Product'));
        self::assertSame($schema, $registry->get('App\\Product'));
    }

    public function testGetOnAnUnregisteredIdentifierThrows(): void {
        $registry = (new InMemorySchemaStores())->registry;

        $this->expectException(InvalidArgumentException::class);

        $registry->get('Never\\Registered');
    }

    public function testRegisterAgainReplacesWhateverWasThereForTheSameIdentifier(): void {
        $registry = (new InMemorySchemaStores())->registry;
        $registry->register(self::emptySchema('App\\Product'));

        $replacement = self::emptySchema('App\\Product');
        $registry->register($replacement);

        self::assertSame($replacement, $registry->get('App\\Product'));
    }

    public function testAFreshRegistryLoadsWhatAnEarlierCompileStored(): void {
        $stores = new InMemorySchemaStores();
        $compiled = $stores->compiler()->compile(self::blueprintsOf([RegistryPiston::class]));

        $registry = self::freshRegistry($stores);

        self::assertTrue($registry->has(RegistryPiston::class));
        self::assertEquals($compiled[RegistryPiston::class], $registry->get(RegistryPiston::class));
    }

    public function testADependentIsStaleOnceItsDependencyChanges(): void {
        $stores = new InMemorySchemaStores();
        $stores->compiler()->compile(self::blueprintsOf([RegistryPiston::class, RegistryEngine::class]));

        $stores->compiler()->compile(self::changed(RegistryPiston::class, ObjectNode::fromArray(['diameter' => new LeafNode(12)])));

        self::assertStaleBecauseOf(RegistryPiston::class, static fn () => self::freshRegistry($stores)->get(RegistryEngine::class));
    }

    public function testRecompilingADependencyUnchangedKeepsTheDependentFresh(): void {
        $stores = new InMemorySchemaStores();
        $compiled = $stores->compiler()->compile(self::blueprintsOf([RegistryPiston::class, RegistryEngine::class]));

        $stores->compiler()->compile(self::blueprintsOf([RegistryPiston::class]));

        self::assertEquals($compiled[RegistryEngine::class], self::freshRegistry($stores)->get(RegistryEngine::class));
    }

    public function testAChangeTwoLevelsDownStillMakesTheDependentStale(): void {
        $stores = new InMemorySchemaStores();
        $stores->compiler()->compile(self::blueprintsOf([RegistryBase::class, RegistryMiddle::class, RegistryLeaf::class]));

        // RegistryMiddle stores nothing of its own either way: only a
        // dependency recorded straight through it can catch this.
        $stores->compiler()->compile(self::changed(RegistryBase::class, ObjectNode::fromArray(['value' => new LeafNode(2)])));

        self::assertStaleBecauseOf(RegistryBase::class, static fn () => self::freshRegistry($stores)->get(RegistryLeaf::class));
    }

    public function testAWeakDependencyChangingMakesTheDependentStaleToo(): void {
        $stores = new InMemorySchemaStores();
        $stores->compiler()->compile(self::blueprintsOf([RegistryPiston::class, RegistryOwner::class]));

        $stores->compiler()->compile(self::changed(RegistryPiston::class, ObjectNode::fromArray(['diameter' => new LeafNode(12)])));

        self::assertStaleBecauseOf(RegistryPiston::class, static fn () => self::freshRegistry($stores)->get(RegistryOwner::class));
    }

    public function testAnOwningReferenceCycleIsStoredWithEachSideDependingOnTheOther(): void {
        $stores = new InMemorySchemaStores();
        $stores->compiler()->compile(self::blueprintsOf([RegistryCycleA::class, RegistryCycleB::class]));

        self::assertSame([RegistryCycleB::class], array_keys($stores->schemas->load(RegistryCycleA::class)->dependencies));
        self::assertSame([RegistryCycleA::class], array_keys($stores->schemas->load(RegistryCycleB::class)->dependencies));
        self::freshRegistry($stores)->get(RegistryCycleA::class);
    }
}

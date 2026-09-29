<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\Changeset\Changeset;
use XyloIsCoding\CoconutCms\Storage\Changeset\ChangesetFlusher;
use XyloIsCoding\CoconutCms\Storage\Changeset\EntityChange;
use XyloIsCoding\CoconutCms\Storage\Changeset\InMemoryUndoLog;
use XyloIsCoding\CoconutCms\Storage\Changeset\TempId;
use XyloIsCoding\CoconutCms\Storage\DynamicEntity;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Attributes\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Attributes\Ownership;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\Schema\InMemorySchemaUndoLog;
use XyloIsCoding\CoconutCms\Storage\Schema\PrototypeRegistry;
use XyloIsCoding\CoconutCms\Storage\Schema\SchemaEditor;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Storage\ValidationException;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\DynamicEntity\ValidatedEvent;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Schema\ExtensibleProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Schema\SimpleActor;

/**
 * Phase 7, Step A: an editor-created prototype's instance reads and writes through the
 * exact same Repository/ChangesetFlusher path a native class already uses, as a
 * DynamicEntity instead of a reflected object.
 */
final class DynamicEntityTest extends TestCase
{
    private Connection $connection;
    private PrototypeRegistry $registry;
    private SchemaEditor $editor;
    private SimpleActor $manager;

    /** @var array<string, string> */
    private array $tables = [ExtensibleProduct::class => 'extensible_products'];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $synchronizer = new SchemaSynchronizer($this->connection);
        $synchronizer->syncAll(PrototypeRegistry::schemaTables());
        $synchronizer->sync(SchemaBuilder::entitiesTable());
        $synchronizer->sync(SchemaBuilder::tableFor('extensible_products', PrototypeShape::ofClass(ExtensibleProduct::class)));

        $this->registry = new PrototypeRegistry($this->connection);
        $this->editor = new SchemaEditor($this->connection, $this->registry, $synchronizer, new InMemorySchemaUndoLog(), $this->tables);
        $this->manager = new SimpleActor(['store-manager']);
    }

    /** Shares $this->registry with EntityManager, the same instance SchemaEditor already used to define the prototype. */
    private function entityManager(): EntityManager
    {
        return new EntityManager($this->connection, $this->tables, $this->registry);
    }

    public function testInsertFindUpdateDeleteRoundTripForAnEditorCreatedPrototype(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [
            FieldDescriptor::scalar('voltage', FieldKind::Int, 'Voltage', queryable: true),
        ], $this->manager);
        $this->tables['Electronics'] = $this->editor->tableOf('Electronics');

        $repository = $this->entityManager()->repository('Electronics');
        $id = $repository->insert(new DynamicEntity('Electronics', ['sku' => 'E-1', 'voltage' => 110]));

        $found = $repository->find($id);
        self::assertInstanceOf(DynamicEntity::class, $found);
        self::assertSame('E-1', $found->get('sku'));
        self::assertSame(110, $found->get('voltage'));

        $updated = $repository->update($id, ['voltage' => 220]);
        self::assertSame(220, $updated->get('voltage'));

        $repository->delete($id);
        self::assertNull($repository->find($id));
    }

    public function testChangesetFlusherCreatesAndFindsAnEditorCreatedInstance(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [
            FieldDescriptor::scalar('voltage', FieldKind::Int, 'Voltage', queryable: true),
        ], $this->manager);
        $this->tables['Electronics'] = $this->editor->tableOf('Electronics');

        $entityManager = $this->entityManager();
        $flusher = new ChangesetFlusher($this->connection, $entityManager, new InMemoryUndoLog());

        $tempId = new TempId('e1');
        $result = $flusher->flush(new Changeset([
            EntityChange::create($tempId, 'Electronics', ['sku' => 'E-1', 'voltage' => 110]),
        ]));

        $found = $entityManager->repository('Electronics')->find($result->resolvedIds['e1']);
        self::assertInstanceOf(DynamicEntity::class, $found);
        self::assertSame(110, $found->get('voltage'));
        self::assertNotNull($result->operationId);
    }

    public function testAChainMixingNativeAndEditorCreatedLevelsRoundTrips(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [
            FieldDescriptor::scalar('voltage', FieldKind::Int, 'Voltage', queryable: true),
        ], $this->manager);
        $this->tables['Electronics'] = $this->editor->tableOf('Electronics');

        $this->editor->createPrototype('PremiumElectronics', 'Electronics', [
            FieldDescriptor::scalar('extraSupport', FieldKind::Bool, 'Extra Support', queryable: true),
        ], $this->manager);
        $this->tables['PremiumElectronics'] = $this->editor->tableOf('PremiumElectronics');

        $repository = $this->entityManager()->repository('PremiumElectronics');
        $id = $repository->insert(new DynamicEntity('PremiumElectronics', [
            'sku' => 'E-2',
            'voltage' => 240,
            'extraSupport' => true,
        ]));

        $found = $repository->find($id);
        self::assertSame('E-2', $found->get('sku'));
        self::assertSame(240, $found->get('voltage'));
        self::assertTrue($found->get('extraSupport'));
    }

    public function testANativeAncestorsPrototypeValidatorStillAppliesToAnEditorCreatedSubclass(): void
    {
        $this->tables[ValidatedEvent::class] = 'validated_events';
        (new SchemaSynchronizer($this->connection))->sync(SchemaBuilder::tableFor('validated_events', PrototypeShape::ofClass(ValidatedEvent::class)));

        // ValidatedEvent has to be registered before this editor is constructed:
        // SchemaEditor takes its own $tables array by value, so a mutation to
        // $this->tables after $this->editor already exists would never be seen by it.
        $editor = new SchemaEditor($this->connection, $this->registry, new SchemaSynchronizer($this->connection), new InMemorySchemaUndoLog(), $this->tables);
        $editor->createPrototype('SpecialEvent', ValidatedEvent::class, [], $this->manager);
        $this->tables['SpecialEvent'] = $editor->tableOf('SpecialEvent');

        $repository = $this->entityManager()->repository('SpecialEvent');

        $this->expectException(ValidationException::class);

        $repository->insert(new DynamicEntity('SpecialEvent', ['startDay' => 10, 'endDay' => 5]));
    }

    public function testASharedReferenceToAnotherEditorCreatedPrototypeIsLazy(): void
    {
        $this->editor->createPrototype('Manufacturer', ExtensibleProduct::class, [], $this->manager);
        $this->tables['Manufacturer'] = $this->editor->tableOf('Manufacturer');

        $this->editor->createPrototype('Gadget', ExtensibleProduct::class, [
            FieldDescriptor::reference('manufacturer', 'Manufacturer', Ownership::Shared, 'Manufacturer'),
        ], $this->manager);
        $this->tables['Gadget'] = $this->editor->tableOf('Gadget');

        $entityManager = $this->entityManager();
        $manufacturer = $entityManager->repository('Manufacturer')->find(
            $entityManager->repository('Manufacturer')->insert(new DynamicEntity('Manufacturer', ['sku' => 'MFG-1'])),
        );

        $gadgetId = $entityManager->repository('Gadget')->insert(
            new DynamicEntity('Gadget', ['sku' => 'GADGET-1', 'manufacturer' => $manufacturer]),
        );

        // A fresh EntityManager/IdentityMap, so the reference is genuinely reloaded, not reused from the one above.
        $freshManager = $this->entityManager();
        $found = $freshManager->repository('Gadget')->find($gadgetId);
        $reference = $found->get('manufacturer');

        self::assertInstanceOf(DynamicEntity::class, $reference);
        self::assertTrue((new ReflectionClass(DynamicEntity::class))->isUninitializedLazyObject($reference));

        self::assertSame('MFG-1', $reference->get('sku'));
        self::assertFalse((new ReflectionClass(DynamicEntity::class))->isUninitializedLazyObject($reference));
    }
}

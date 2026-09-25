<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Prototype;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use LogicException;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Field\Ownership;
use XyloIsCoding\CoconutCms\Storage\Permission\SchemaPermissionDenied;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\Prototype\InMemorySchemaUndoLog;
use XyloIsCoding\CoconutCms\Storage\Prototype\PrototypeRegistry;
use XyloIsCoding\CoconutCms\Storage\Prototype\SchemaEditor;
use XyloIsCoding\CoconutCms\Storage\Prototype\SchemaOperationKind;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Prototype\ExtensibleProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Prototype\SealedProduct;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Prototype\SimpleActor;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Relations\Tag;

final class SchemaEditorTest extends TestCase
{
    private Connection $connection;
    private PrototypeRegistry $registry;
    private InMemorySchemaUndoLog $undoLog;
    private SchemaEditor $editor;
    private SimpleActor $manager;
    private SimpleActor $outsider;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');

        $synchronizer = new SchemaSynchronizer($this->connection);
        $synchronizer->syncAll(PrototypeRegistry::schemaTables());
        $synchronizer->sync(SchemaBuilder::tableFor('extensible_products', PrototypeShape::ofClass(ExtensibleProduct::class)));
        $synchronizer->sync(SchemaBuilder::tableFor('sealed_products', PrototypeShape::ofClass(SealedProduct::class)));
        $synchronizer->sync(SchemaBuilder::tableFor('tags', PrototypeShape::ofClass(Tag::class)));

        $this->registry = new PrototypeRegistry($this->connection);
        $this->undoLog = new InMemorySchemaUndoLog();
        $this->editor = new SchemaEditor($this->connection, $this->registry, $synchronizer, $this->undoLog, [
            ExtensibleProduct::class => 'extensible_products',
            SealedProduct::class => 'sealed_products',
            Tag::class => 'tags',
        ]);

        $this->manager = new SimpleActor(['store-manager']);
        $this->outsider = new SimpleActor([]);
    }

    public function testCreatingASubclassWithoutPermissionIsRejectedBeforeAnyDdl(): void
    {
        try {
            $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [], $this->outsider);
            self::fail('expected SchemaPermissionDenied');
        } catch (SchemaPermissionDenied) {
            // expected
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM prototypes'));
    }

    public function testCreatingASubclassWithPermissionCreatesItsOwnTable(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [
            FieldDescriptor::scalar('voltage', FieldKind::Int, 'Voltage', queryable: true),
        ], $this->manager);

        $columns = array_keys($this->connection->createSchemaManager()->listTableColumns($this->editor->tableOf('Electronics')));
        self::assertSame(['id', 'voltage', 'data'], $columns);
    }

    public function testCannotExtendANativeClassWithoutEditorExtensible(): void
    {
        $this->expectException(LogicException::class);

        $this->editor->createPrototype('NotAllowed', SealedProduct::class, [], $this->manager);
    }

    public function testAddColumnWithoutPermissionIsRejected(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [], $this->manager);

        $this->expectException(SchemaPermissionDenied::class);

        $this->editor->addColumn('Electronics', FieldDescriptor::scalar('warranty', FieldKind::Int, 'Warranty'), $this->outsider);
    }

    public function testAddColumnNeverTouchesTheParentsTable(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [], $this->manager);
        $parentColumnsBefore = $this->connection->createSchemaManager()->listTableColumns('extensible_products');

        $this->editor->addColumn('Electronics', FieldDescriptor::scalar('warranty', FieldKind::Int, 'Warranty', queryable: true), $this->manager);

        $parentColumnsAfter = $this->connection->createSchemaManager()->listTableColumns('extensible_products');
        self::assertSame(array_keys($parentColumnsBefore), array_keys($parentColumnsAfter));
        self::assertContains('warranty', array_keys($this->connection->createSchemaManager()->listTableColumns($this->editor->tableOf('Electronics'))));
    }

    public function testCannotAddOrDropColumnsOnANativeClass(): void
    {
        $this->expectException(LogicException::class);

        $this->editor->addColumn(ExtensibleProduct::class, FieldDescriptor::scalar('x', FieldKind::Int, 'X'), $this->manager);
    }

    public function testDropColumnSnapshotsExistingDataBeforeDropping(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [
            FieldDescriptor::scalar('voltage', FieldKind::Int, 'Voltage', queryable: true),
        ], $this->manager);
        $table = $this->editor->tableOf('Electronics');

        $this->connection->insert('extensible_products', ['sku' => 'A1', 'data' => '{}']);
        $id1 = $this->connection->lastInsertId();
        $this->connection->insert($table, ['id' => $id1, 'voltage' => 110, 'data' => '{}']);

        $operation = $this->editor->dropColumn('Electronics', 'voltage', $this->manager);

        self::assertSame(SchemaOperationKind::DropColumn, $operation->kind);
        self::assertSame('voltage', $operation->field->name);
        self::assertSame([(string) $id1 => 110], $operation->snapshot);
        self::assertNotContains('voltage', array_keys($this->connection->createSchemaManager()->listTableColumns($table)));
    }

    public function testUndoDropColumnRestoresTheColumnAndItsData(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [
            FieldDescriptor::scalar('voltage', FieldKind::Int, 'Voltage', queryable: true),
        ], $this->manager);
        $table = $this->editor->tableOf('Electronics');

        $this->connection->insert('extensible_products', ['sku' => 'A1', 'data' => '{}']);
        $id = $this->connection->lastInsertId();
        $this->connection->insert($table, ['id' => $id, 'voltage' => 110, 'data' => '{}']);

        $operation = $this->editor->dropColumn('Electronics', 'voltage', $this->manager);
        $this->editor->undoDropColumn($operation, $this->manager);

        self::assertContains('voltage', array_keys($this->connection->createSchemaManager()->listTableColumns($table)));
        self::assertSame(110, (int) $this->connection->fetchOne(sprintf('SELECT voltage FROM %s WHERE id = ?', $table), [$id]));
    }

    public function testABlobOnlyFieldHasNoColumnToSnapshotOrDrop(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [
            FieldDescriptor::scalar('notes', FieldKind::String, 'Notes'),
        ], $this->manager);

        $operation = $this->editor->dropColumn('Electronics', 'notes', $this->manager);

        self::assertSame([], $operation->snapshot);
    }

    public function testArbitraryChainDepthMixingNativeAndEditorCreatedLevels(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [], $this->manager);
        $this->editor->createPrototype('PremiumElectronics', 'Electronics', [
            FieldDescriptor::scalar('extraSupport', FieldKind::Bool, 'Extra Support', queryable: true),
        ], $this->manager);

        self::assertSame(
            [ExtensibleProduct::class, 'Electronics', 'PremiumElectronics'],
            $this->registry->chainOf('PremiumElectronics'),
        );
        self::assertContains('extrasupport', array_keys($this->connection->createSchemaManager()->listTableColumns($this->editor->tableOf('PremiumElectronics'))));
    }

    public function testRenameWithoutPermissionIsRejected(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [], $this->manager);

        $this->expectException(SchemaPermissionDenied::class);

        $this->editor->rename('Electronics', 'Gadgets', $this->outsider);
    }

    public function testCannotRenameANativeClass(): void
    {
        $this->expectException(LogicException::class);

        $this->editor->rename(ExtensibleProduct::class, 'Whatever', $this->manager);
    }

    public function testRenameMovesDataToTheNewTableAndUpdatesTableOf(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [
            FieldDescriptor::scalar('voltage', FieldKind::Int, 'Voltage', queryable: true),
        ], $this->manager);
        $oldTable = $this->editor->tableOf('Electronics');

        $this->connection->insert('extensible_products', ['sku' => 'A1', 'data' => '{}']);
        $id = $this->connection->lastInsertId();
        $this->connection->insert($oldTable, ['id' => $id, 'voltage' => 110, 'data' => '{}']);

        $this->editor->rename('Electronics', 'Gadgets', $this->manager);

        self::assertSame('gadgets', $this->editor->tableOf('Gadgets'));
        self::assertFalse(in_array($oldTable, $this->connection->createSchemaManager()->listTableNames(), true));
        self::assertSame(110, (int) $this->connection->fetchOne('SELECT voltage FROM gadgets WHERE id = ?', [$id]));
    }

    public function testRenameCascadesToAChildPrototypesParentAndToAFieldsReferencedShape(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [], $this->manager);
        $this->editor->createPrototype('PremiumElectronics', 'Electronics', [], $this->manager);
        $this->editor->createPrototype('Warranty', ExtensibleProduct::class, [
            FieldDescriptor::reference('coveredProduct', 'Electronics', Ownership::Shared, 'Covered product'),
        ], $this->manager);

        $this->editor->rename('Electronics', 'Gadgets', $this->manager);

        self::assertSame('Gadgets', $this->registry->parentOf('PremiumElectronics'));
        self::assertSame('Gadgets', $this->registry->ownFieldsOf('Warranty')[0]->referencedShape);

        // Proves the FK on warranty.coveredproduct_id followed the table rename by
        // itself, no manual fixup: inserting against the new "gadgets" table succeeds.
        $this->connection->insert('extensible_products', ['sku' => 'A1', 'data' => '{}']);
        $productId = $this->connection->lastInsertId();
        $this->connection->insert('gadgets', ['id' => $productId, 'data' => '{}']);

        $this->connection->insert('extensible_products', ['sku' => 'A2', 'data' => '{}']);
        $warrantyId = $this->connection->lastInsertId();
        $this->connection->insert('warranty', ['id' => $warrantyId, 'coveredproduct_id' => $productId, 'data' => '{}']);

        self::assertSame($productId, (string) $this->connection->fetchOne('SELECT coveredproduct_id FROM warranty WHERE id = ?', [$warrantyId]));
    }

    public function testRenameMovesOwnCollectionJoinTableToo(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [
            FieldDescriptor::collection('tags', FieldKind::EntityReference, Tag::class, 'Tags', ownership: Ownership::Shared),
        ], $this->manager);

        $this->editor->rename('Electronics', 'Gadgets', $this->manager);

        $tableNames = $this->connection->createSchemaManager()->listTableNames();
        self::assertContains('gadgets_tags', $tableNames);
        self::assertNotContains('electronics_tags', $tableNames);
    }

    public function testCannotRenameToAnIdentifierThatAlreadyExists(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [], $this->manager);
        $this->editor->createPrototype('Appliances', ExtensibleProduct::class, [], $this->manager);

        $this->expectException(LogicException::class);

        $this->editor->rename('Electronics', 'Appliances', $this->manager);
    }

    public function testCannotRenameToATableNameThatAlreadyExists(): void
    {
        $this->editor->createPrototype('Electronics', ExtensibleProduct::class, [], $this->manager);

        $this->expectException(LogicException::class);

        $this->editor->rename('Electronics', 'Sealed_Products', $this->manager);
    }
}

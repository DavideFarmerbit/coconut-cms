<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Prototype\PrototypeRegistry;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;
use XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Prototype\ExtensibleProduct;

final class PrototypeRegistryTest extends TestCase
{
    private Connection $connection;
    private PrototypeRegistry $registry;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        (new SchemaSynchronizer($this->connection))->syncAll(PrototypeRegistry::schemaTables());
        $this->registry = new PrototypeRegistry($this->connection);
    }

    public function testNativeClassesAreNotEditorCreated(): void
    {
        self::assertFalse($this->registry->isEditorCreated(ExtensibleProduct::class));
    }

    public function testAPersistedPrototypeIsEditorCreated(): void
    {
        $this->registry->define('Electronics', ExtensibleProduct::class);

        self::assertTrue($this->registry->isEditorCreated('Electronics'));
    }

    public function testOwnFieldsRoundTripThroughStorageForEveryKind(): void
    {
        $this->registry->define('Electronics', ExtensibleProduct::class);
        $this->registry->addField('Electronics', FieldDescriptor::scalar('voltage', FieldKind::Int, 'Voltage', queryable: true, unique: true));
        $this->registry->addField('Electronics', FieldDescriptor::choice('status', ['draft', 'live'], 'Status'));

        $fields = $this->registry->ownFieldsOf('Electronics');

        self::assertSame('voltage', $fields[0]->name);
        self::assertSame(FieldKind::Int, $fields[0]->kind);
        self::assertTrue($fields[0]->queryable);
        self::assertTrue($fields[0]->unique);
        self::assertSame(['draft', 'live'], $fields[1]->choiceOptions);
    }

    public function testChainOfMixesNativeAndEditorCreatedLevels(): void
    {
        $this->registry->define('Electronics', ExtensibleProduct::class);
        $this->registry->define('PremiumElectronics', 'Electronics');

        self::assertSame(
            [ExtensibleProduct::class, 'Electronics', 'PremiumElectronics'],
            $this->registry->chainOf('PremiumElectronics'),
        );
    }

    public function testFieldsOfConcatenatesEveryLevelsOwnFieldsBaseFirst(): void
    {
        $this->registry->define('Electronics', ExtensibleProduct::class);
        $this->registry->addField('Electronics', FieldDescriptor::scalar('voltage', FieldKind::Int, 'Voltage'));

        $names = array_map(static fn (FieldDescriptor $f): string => $f->name, $this->registry->fieldsOf('Electronics'));

        self::assertSame(['sku', 'voltage'], $names);
    }

    public function testNearestNativeAncestorWalksPastEditorCreatedLevels(): void
    {
        $this->registry->define('Electronics', ExtensibleProduct::class);
        $this->registry->define('PremiumElectronics', 'Electronics');

        self::assertSame(ExtensibleProduct::class, $this->registry->nearestNativeAncestor('PremiumElectronics'));
    }

    public function testDefineRejectsANameThatCollidesWithARealClass(): void
    {
        $this->expectException(\LogicException::class);

        $this->registry->define(ExtensibleProduct::class, null);
    }
}

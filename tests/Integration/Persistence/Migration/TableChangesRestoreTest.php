<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;
use XyloIsCoding\CoconutCms\Persistence\Migration\TableChanges;
use XyloIsCoding\CoconutCms\Tests\Integration\TestDatabase;

/** Putting the tables back after an expand no move followed: every value where it was. */
final class TableChangesRestoreTest extends TestCase
{
    private Connection $connection;
    private TableChanges $tables;

    protected function setUp(): void {
        $this->connection = TestDatabase::connect();
        TestDatabase::dropEverything($this->connection);
        $this->tables = new TableChanges($this->connection);

        $this->create(self::original());
        $this->connection->insert('restore_parent', ['id' => 'p', 'title' => 'first']);
        $this->connection->insert('restore_child', ['id' => 'c', 'parent' => 'p', 'weight' => 7]);
    }

    protected function tearDown(): void {
        TestDatabase::dropEverything($this->connection);
        $this->connection->close();
    }

    /** A parent table, and a child pointing at it with an indexed column. String ids, as every content table has. */
    private static function original(): Schema {
        $schema = new Schema();
        $parent = $schema->createTable('restore_parent');
        $parent->addColumn('id', Types::STRING, ['length' => 36]);
        $parent->addColumn('title', Types::STRING, ['length' => 50, 'notnull' => false]);
        $parent->setPrimaryKey(['id']);

        $child = $schema->createTable('restore_child');
        $child->addColumn('id', Types::STRING, ['length' => 36]);
        $child->addColumn('parent', Types::STRING, ['length' => 36, 'notnull' => false]);
        $child->addColumn('weight', Types::INTEGER, ['notnull' => false]);
        $child->setPrimaryKey(['id']);
        $child->addIndex(['weight'], 'restore_weight');
        $child->addForeignKeyConstraint('restore_parent', ['parent'], ['id'], ['onDelete' => 'SET NULL'], 'restore_child_parent');

        return $schema;
    }

    private function create(Schema $schema): void {
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    /** @return array<string, string> Each column of $table and its type, by name. */
    private function columnsOf(string $table): array {
        $columns = [];
        foreach ($this->connection->createSchemaManager()->introspectTable($table)->getColumns() as $column) {
            $columns[$column->getName()] = $column->getType()::class;
        }
        ksort($columns);

        return $columns;
    }

    /** @return list<string> Every foreign key's table and target, of the tables here. */
    private function foreignKeys(): array {
        $keys = [];
        foreach (['restore_child'] as $table) {
            foreach ($this->connection->createSchemaManager()->introspectTable($table)->getForeignKeys() as $key) {
                $keys[] = $table . ' -> ' . $key->getForeignTableName();
            }
        }

        return $keys;
    }

    public function testAColumnMovedAsideComesBackWithItsValuesAndItsIndex(): void {
        $before = $this->columnsOf('restore_child');
        $target = self::original();
        $target->getTable('restore_child')->modifyColumn('weight', ['type' => \Doctrine\DBAL\Types\Type::getType(Types::STRING), 'length' => 20]);
        $target->getTable('restore_child')->addColumn('added', Types::INTEGER, ['notnull' => false]);

        $this->tables->expand($target);
        self::assertArrayHasKey('_old__weight', $this->columnsOf('restore_child'));

        $this->tables->restore(self::original(), ['restore_parent', 'restore_child']);

        self::assertSame($before, $this->columnsOf('restore_child'), 'What expand added is gone, and the column is back.');
        self::assertSame(7, (int) $this->connection->fetchOne("SELECT weight FROM restore_child WHERE id = 'c'"));
        self::assertTrue($this->connection->createSchemaManager()->introspectTable('restore_child')->hasIndex('restore_weight'));
    }

    public function testATableMovedAsideComesBackWithItsRowsAndWhatPointsAtIt(): void {
        $target = self::original();
        $parent = $target->getTable('restore_parent');
        $parent->addColumn('position', Types::INTEGER, ['default' => 0]);
        $parent->dropPrimaryKey();
        $parent->setPrimaryKey(['id', 'position']);
        $target->getTable('restore_child')->removeForeignKey('restore_child_parent');

        $moved = $this->tables->expand($target);
        self::assertSame(['restore_parent'], $moved);
        self::assertSame([], $this->foreignKeys());

        $this->tables->restore(self::original(), ['restore_parent', 'restore_child']);

        self::assertSame('first', $this->connection->fetchOne("SELECT title FROM restore_parent WHERE id = 'p'"));
        self::assertNotContains('_old__restore_parent', $this->connection->createSchemaManager()->listTableNames());
        self::assertSame(['restore_child -> restore_parent'], $this->foreignKeys());
    }

    public function testRestoringWhatsAlreadyInPlaceChangesNothing(): void {
        $before = $this->columnsOf('restore_child');

        $this->tables->restore(self::original(), ['restore_parent', 'restore_child']);

        self::assertSame($before, $this->columnsOf('restore_child'));
        self::assertSame(7, (int) $this->connection->fetchOne("SELECT weight FROM restore_child WHERE id = 'c'"));
    }
}

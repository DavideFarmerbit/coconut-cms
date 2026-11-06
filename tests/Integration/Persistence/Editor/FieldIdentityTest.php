<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Editor;

use Doctrine\DBAL\Schema\Column;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin\NumberToText;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSave;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RemoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\MigrateOn;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Persistence;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicModule;
use XyloIsCoding\CoconutCms\Tests\Integration\Persistence\Dynamic\DynamicTestCase;
use XyloIsCoding\CoconutCms\Tests\Integration\StoredColumns;

/** A field is stored by its id and its column, never by its name; every save stamps what it writes. */
final class FieldIdentityTest extends DynamicTestCase
{
    private function save(object ...$operations): void {
        $persistence = Persistence::boot($this->connection, [new DynamicModule()], null, MigrateOn::Command, new StrictPolicy());
        $persistence->saveSchemas(new EditorSave((new MigrationState($this->connection))->revision(), array_values($operations)), new SystemActor());
    }

    private function columns(): StoredColumns {
        return new StoredColumns($this->connection);
    }

    /** @return array{string, string} Field $field of the yacht: its id, and its column. */
    private function identityOfYacht(string $field): array {
        [$schemas] = $this->stores();
        $identity = $schemas->load('dy:yacht')->schema->fields->get(FieldName::of($field))->identity();

        return [$identity->id, $identity->column];
    }

    /** @return list<string> The columns of the yacht's table. */
    private function yachtColumns(): array {
        return array_values(array_map(static fn (Column $column): string => $column->getName(), $this->connection->createSchemaManager()->introspectTable('dy_yacht')->getColumns()));
    }

    private function hasYachtColumn(string $column): bool {
        return $this->connection->createSchemaManager()->introspectTable('dy_yacht')->hasColumn($column);
    }

    private function token(string $table, string $column, string $id): string {
        return (string) $this->connection->fetchOne(sprintf('SELECT %s FROM %s WHERE %s = ?', $column, $table, $table === 'entities' ? 'id' : ColumnNames::ID), [$id]);
    }

    public function testARenameKeepsTheFieldsIdAndColumn(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);
        $before = $this->identityOfYacht('cabins');

        $this->save(new RenameField('dy:yacht', 'cabins', 'rooms'));

        self::assertSame($before, $this->identityOfYacht('rooms'));
        self::assertSame(5, $this->session()->loadDynamic($yacht)->get('rooms'));
    }

    public function testARetypeKeepsTheFieldsIdAndGivesItANewColumn(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);
        [$id, $column] = $this->identityOfYacht('cabins');

        $this->save(new RetypeField('dy:yacht', FieldDescriptor::scalar('cabins', ScalarType::String, Nullability::NotNullable), new NumberToText()));

        [$newId, $newColumn] = $this->identityOfYacht('cabins');
        self::assertSame($id, $newId);
        self::assertNotSame($column, $newColumn);
        self::assertFalse($this->hasYachtColumn($column), 'Contract took the old column.');
        self::assertSame('5', $this->session()->loadDynamic($yacht)->get('cabins'));
    }

    public function testRemovingThenAddingTheSameNameIsANewField(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5]);
        [$id, $column] = $this->identityOfYacht('cabins');

        $this->save(new RemoveField('dy:yacht', 'cabins'));
        $this->save(new AddField('dy:yacht', FieldDescriptor::scalar('cabins', ScalarType::Int, Nullability::NotNullable), new DefaultValue(1)));

        [$newId, $newColumn] = $this->identityOfYacht('cabins');
        self::assertNotSame($id, $newId);
        self::assertNotSame($column, $newColumn);
        self::assertSame(1, $this->session()->loadDynamic($yacht)->get('cabins'), 'A new field: nothing of the old one comes back.');
    }

    public function testOwnedRowsHoldTheFieldsIdNeverItsName(): void {
        $yacht = $this->store('dy:yacht', ['dinghy' => 'dy:motor', 'dinghy.horsepower' => 40]);
        [$dinghy] = $this->ownedIds($yacht, 'dinghy');

        $held = $this->connection->fetchOne('SELECT owner_field FROM entities WHERE id = ?', [$dinghy]);

        self::assertSame($this->columns()->ownerFieldOf('dy:yacht', 'dinghy'), $held);
        self::assertNotSame('dinghy', $held);
    }

    public function testRenamingAnOwnedFieldLeavesItsRowsPointing(): void {
        $yacht = $this->store('dy:yacht', ['dinghy' => 'dy:motor', 'dinghy.horsepower' => 40]);
        [$dinghy] = $this->ownedIds($yacht, 'dinghy');
        $ownerField = $this->connection->fetchOne('SELECT owner_field FROM entities WHERE id = ?', [$dinghy]);

        $this->save(new RenameField('dy:yacht', 'dinghy', 'tender'));

        self::assertSame($ownerField, $this->connection->fetchOne('SELECT owner_field FROM entities WHERE id = ?', [$dinghy]));
        self::assertSame([$dinghy], $this->ownedIds($yacht, 'tender'));
        self::assertSame(40, $this->session()->loadDynamic($yacht)->get('tender')->get('horsepower'));
    }

    public function testEverySaveStampsEveryRowItWritesAFreshToken(): void {
        $yacht = $this->store('dy:yacht', ['cabins' => 5, 'dinghy' => 'dy:motor', 'dinghy.horsepower' => 40]);
        [$dinghy] = $this->ownedIds($yacht, 'dinghy');
        $tokens = fn (): array => [
            $this->token('entities', 'written', $yacht),
            $this->token('dy_yacht', ColumnNames::WRITTEN, $yacht),
            $this->token('entities', 'written', $dinghy),
        ];
        $first = $tokens();

        $session = $this->session();
        $entity = $session->loadDynamic($yacht);
        $entity->set('cabins', 6);
        $session->save($entity);

        foreach ($tokens() as $index => $token) {
            self::assertNotSame($first[$index], $token);
        }
    }

    public function testASaveChangingOnlyACollectionStampsItsOwnersRow(): void {
        $this->save(new AddField('dy:buoy', FieldDescriptor::collection('numbers', FieldDescriptor::scalar('numbers', ScalarType::Int, Nullability::NotNullable)), new DefaultValue([])));
        $buoy = $this->store('dy:buoy', ['color' => 'red']);
        $before = $this->token('dy_buoy', ColumnNames::WRITTEN, $buoy);

        $session = $this->session();
        $entity = $session->loadDynamic($buoy);
        $entity->set('numbers', [7]);
        $session->save($entity);

        self::assertNotSame($before, $this->token('dy_buoy', ColumnNames::WRITTEN, $buoy));
    }

    public function testAFieldsColumnSaysNothingOfItsName(): void {
        [, $column] = $this->identityOfYacht('cabins');

        self::assertContains($column, $this->yachtColumns());
        self::assertNotContains('cabins', $this->yachtColumns());
        self::assertMatchesRegularExpression('/^f_[0-9a-f]{6}$/', $column);
    }
}

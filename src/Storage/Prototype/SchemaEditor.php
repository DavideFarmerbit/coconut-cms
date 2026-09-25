<?php

namespace XyloIsCoding\CoconutCms\Storage\Prototype;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use LogicException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\Field\EditorExtensible;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\Permission\Actor;
use XyloIsCoding\CoconutCms\Storage\Permission\SchemaPermission;
use XyloIsCoding\CoconutCms\Storage\Permission\SchemaPermissionDenied;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;
use XyloIsCoding\CoconutCms\Storage\SchemaSynchronizer;

/**
 * The admin-facing schema-mutation API: create a prototype, add/drop a column on an
 * editor-created one. Every operation checks SchemaPermission before any DBAL diff or
 * DDL runs, "fail before, not during", same posture as every other write-path check in
 * this codebase.
 *
 * Only ever touches the target prototype's own table (plus any join/child table one of
 * its own fields needs), never a parent's or a sibling's, even though building the
 * desired schema walks the whole chain to get FK-vs-autoincrement right for the id
 * column, syncing is filtered down to just the levels that could plausibly differ.
 *
 * Creating a totally fresh, parentless prototype (no `#[EditorExtensible]` native
 * ancestor to inherit permission from) isn't supported yet, every example in
 * ARCHITECTURE.md is about extending something that already exists; a real "can this
 * actor create schema at all" capability would need its own design.
 */
final class SchemaEditor
{
    /** @param array<string, string> $tables identifier => table name, grows as createPrototype() is called */
    public function __construct(
        private readonly Connection $connection,
        private readonly PrototypeRegistry $registry,
        private readonly SchemaSynchronizer $synchronizer,
        private readonly SchemaUndoLog $undoLog,
        private array $tables,
    ) {
    }

    public function tableOf(string $identifier): string
    {
        return $this->tables[$identifier] ?? throw new LogicException(sprintf('No table registered for "%s".', $identifier));
    }

    /** @param FieldDescriptor[] $ownFields */
    public function createPrototype(string $name, string $parent, array $ownFields, Actor $actor): void
    {
        $this->assertCanWrite($parent, $actor);

        $this->registry->define($name, $parent);
        foreach ($ownFields as $field) {
            $this->registry->addField($name, $field);
        }

        // Lowercased rather than reused verbatim: DBAL's own schema introspection matches
        // table names case-sensitively even on engines (SQLite, MySQL on some platforms)
        // where the underlying SQL itself is not, so a mixed-case admin-chosen name would
        // silently desync from what listTableColumns()/etc. can actually find afterward.
        $this->tables[$name] = strtolower($name);
        $this->syncOwnTable($name);
    }

    public function addColumn(string $identifier, FieldDescriptor $field, Actor $actor): void
    {
        $this->assertEditorCreated($identifier, 'add a column to');
        $this->assertCanWrite($identifier, $actor);

        $this->registry->addField($identifier, $field);
        $this->syncOwnTable($identifier);

        $this->undoLog->record(SchemaOperationKind::AddColumn, $identifier, $field);
    }

    public function dropColumn(string $identifier, string $fieldName, Actor $actor): SchemaOperation
    {
        $this->assertEditorCreated($identifier, 'drop a column from');
        $this->assertCanWrite($identifier, $actor);

        $field = $this->ownFieldNamed($identifier, $fieldName);
        $snapshot = $this->snapshotColumn($identifier, $field);

        $this->registry->removeField($identifier, $fieldName);
        $this->syncOwnTable($identifier);

        return $this->undoLog->record(SchemaOperationKind::DropColumn, $identifier, $field, $snapshot);
    }

    /**
     * Renames an editor-created prototype: its own table, any of its own Collection
     * fields' join/child tables (name-prefixed off its own table), the prototypes.name
     * row itself, and every prototypes.parent/prototype_fields.referenced_shape row
     * that mentioned the old name. FK constraints pointing at the renamed table need
     * no manual fixup, the database engine tracks them by internal identity, not by
     * name.
     */
    public function rename(string $identifier, string $newName, Actor $actor): void
    {
        $this->assertEditorCreated($identifier, 'rename');
        $this->assertCanWrite($identifier, $actor);

        if (class_exists($newName) || $this->registry->exists($newName)) {
            throw new LogicException(sprintf('"%s" already identifies something else.', $newName));
        }

        $oldTable = $this->tableOf($identifier);
        $newTable = strtolower($newName);
        $this->assertTableAvailable($newTable);

        $schemaManager = $this->connection->createSchemaManager();
        foreach ($this->ownTableNames($identifier) as $tableName) {
            $schemaManager->renameTable($tableName, $newTable . substr($tableName, strlen($oldTable)));
        }

        $this->registry->rename($identifier, $newName);

        unset($this->tables[$identifier]);
        $this->tables[$newName] = $newTable;
    }

    /** Re-adds the dropped column and restores whatever data the pre-drop snapshot captured. */
    public function undoDropColumn(SchemaOperation $operation, Actor $actor): void
    {
        if ($operation->kind !== SchemaOperationKind::DropColumn) {
            throw new LogicException('Only a DropColumn operation can be undone this way.');
        }

        $this->assertCanWrite($operation->prototypeIdentifier, $actor);

        $this->registry->addField($operation->prototypeIdentifier, $operation->field);
        $this->syncOwnTable($operation->prototypeIdentifier);

        $table = $this->tableOf($operation->prototypeIdentifier);
        $column = self::columnOf($operation->field);
        foreach ($operation->snapshot as $entityId => $value) {
            $this->connection->update($table, [$column => $value], ['id' => $entityId]);
        }
    }

    private function assertEditorCreated(string $identifier, string $action): void
    {
        if (!$this->registry->isEditorCreated($identifier)) {
            throw new LogicException(sprintf('Cannot %s "%s", it is a native class, permanently fixed.', $action, $identifier));
        }
    }

    private function assertCanWrite(string $identifier, Actor $actor): void
    {
        $permission = $this->effectivePermission($identifier);

        if ($permission !== null && !$permission->canWrite($actor)) {
            throw new SchemaPermissionDenied(sprintf('Not allowed to modify the schema of "%s".', $identifier));
        }
    }

    /** Walks up to the nearest native ancestor's own #[EditorExtensible] permission; an editor-created level always inherits, no override in v1. */
    private function effectivePermission(string $identifier): ?SchemaPermission
    {
        $nativeAncestor = $this->registry->nearestNativeAncestor($identifier);
        if ($nativeAncestor === null) {
            throw new LogicException(sprintf('"%s" has no native ancestor to resolve EditorExtensible permission from.', $identifier));
        }

        $attributes = (new ReflectionClass($nativeAncestor))->getAttributes(EditorExtensible::class);
        if ($attributes === []) {
            throw new LogicException(sprintf('"%s" is not #[EditorExtensible], admins cannot extend it.', $nativeAncestor));
        }

        return $attributes[0]->newInstance()->permission;
    }

    private function ownFieldNamed(string $identifier, string $fieldName): FieldDescriptor
    {
        foreach ($this->registry->ownFieldsOf($identifier) as $field) {
            if ($field->name === $fieldName) {
                return $field;
            }
        }

        throw new LogicException(sprintf('"%s" has no own field named "%s".', $identifier, $fieldName));
    }

    /**
     * Only a field backed by a real column can be snapshotted/dropped this way. A
     * blob-only field has no column to drop at all, removing it from the definition
     * needs no DDL. Collection/Embed aren't supported yet, restoring a whole table's
     * worth of rows is a bigger undertaking than a single column's values.
     *
     * @return array<string, mixed> entity id => value, empty if there was no real column to snapshot
     */
    private function snapshotColumn(string $identifier, FieldDescriptor $field): array
    {
        if ($field->kind === FieldKind::Collection || $field->kind === FieldKind::EmbeddedValueObject) {
            throw new LogicException(sprintf('Dropping a %s field is not supported yet.', $field->kind->name));
        }

        if (!$field->queryable && $field->kind !== FieldKind::EntityReference) {
            return [];
        }

        $table = $this->tableOf($identifier);
        $column = self::columnOf($field);

        $rows = $this->connection->fetchAllAssociative(sprintf('SELECT %s, %s FROM %s', SchemaBuilder::ID_COLUMN, $column, $table));

        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[(string) $row[SchemaBuilder::ID_COLUMN]] = $row[$column];
        }

        return $snapshot;
    }

    private static function columnOf(FieldDescriptor $field): string
    {
        return $field->kind === FieldKind::EntityReference ? $field->name . '_id' : $field->name;
    }

    /** Builds the whole chain to get the id column right (autoincrement vs FK-to-parent), but only ever syncs $identifier's own table(s), never a parent's or sibling's. */
    private function syncOwnTable(string $identifier): void
    {
        $this->synchronizer->syncAll($this->ownTables($identifier));
    }

    /**
     * $identifier's own table, plus any of its own Collection fields' join/child
     * tables, name-prefixed off it, never a parent's or a sibling's. Shared by
     * syncOwnTable() (needs the Table definitions) and rename() (only needs the names).
     *
     * @return Table[]
     */
    private function ownTables(string $identifier): array
    {
        $tableName = $this->tableOf($identifier);
        $chainTables = SchemaBuilder::tablesForChain($this->registry->chainOf($identifier), $this->tables, $this->registry->ownFieldsOf(...));

        return array_values(array_filter(
            $chainTables,
            static fn (Table $table): bool => $table->getName() === $tableName || str_starts_with($table->getName(), $tableName . '_'),
        ));
    }

    /** @return string[] */
    private function ownTableNames(string $identifier): array
    {
        return array_map(static fn (Table $table): string => $table->getName(), $this->ownTables($identifier));
    }

    private function assertTableAvailable(string $tableName): void
    {
        if (in_array($tableName, $this->connection->createSchemaManager()->listTableNames(), true)) {
            throw new LogicException(sprintf('Table "%s" already exists.', $tableName));
        }
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldIdentity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;

/**
 * Gives every field of a compiled batch its identity. Compiling never does: a
 * class says nothing about ids, so a batch from the code has none. A field
 * takes its identity from the same type's same field in what's known, and a
 * field new there gets a new one. Run once the fixups are planned, since they
 * carry identities through renames and moves.
 */
final class FieldIdentities
{
    /** Hex characters in a field id: never reused, by chance included. */
    private const ID_LENGTH = 12;

    /** Hex characters in a column, after its prefix. */
    private const COLUMN_LENGTH = 6;

    /**
     * $batch with every field identified.
     * @param array<string, EntitySchema> $known What identities are taken
     *        from, keyed by identifier: the schemas as the fixups left them.
     * @param array<string, EntitySchema> $stored What's stored now: a column
     *        still in use is never generated again.
     */
    public static function assign(CompiledBatch $batch, array $known, array $stored = []): CompiledBatch {
        $columns = self::columnsOf([...array_values($stored), ...array_values($known), ...array_values($batch->schemas)]);

        $schemas = [];
        foreach ($batch->schemas as $identifier => $schema) {
            $fields = $schema->fields;
            foreach ($schema->fields as $name => $field) {
                $held = $field->identity() ?? (isset($known[$identifier]) && $known[$identifier]->fields->has($name) ? $known[$identifier]->fields->get($name)->identity() : null);
                $fields = $fields->with($name, $field->withIdentity(new FieldIdentity(
                    $held?->id ?? self::newId(),
                    $held?->column ?? self::newColumn($columns),
                )));
            }

            $schemas[$identifier] = $schema->withFields($fields);
        }

        return new CompiledBatch(
            $schemas,
            $batch->values,
            array_map(static fn (StoredSchema $each): StoredSchema => new StoredSchema($schemas[$each->schema->identifier], $each->source, $each->fingerprint, $each->dependencies), $batch->stored),
        );
    }

    /**
     * Every column $schemas' fields hold, as keys.
     * @param list<EntitySchema> $schemas
     * @return array<string, true>
     */
    private static function columnsOf(array $schemas): array {
        $columns = [];
        foreach ($schemas as $schema) {
            foreach ($schema->fields as $field) {
                $column = $field->identity()?->column;
                if ($column !== null) {
                    $columns[$column] = true;
                }
            }
        }

        return $columns;
    }

    private static function newId(): string {
        return bin2hex(random_bytes(self::ID_LENGTH / 2));
    }

    /**
     * A column no field of any type holds, so nothing landing on one table
     * can clash: not two levels of an Embed's chain, not a field moved in.
     * @param array<string, true> $columns Added to.
     */
    private static function newColumn(array &$columns): string {
        do {
            $column = ColumnNames::FIELD . bin2hex(random_bytes(self::COLUMN_LENGTH / 2));
        } while (isset($columns[$column]));

        $columns[$column] = true;

        return $column;
    }
}

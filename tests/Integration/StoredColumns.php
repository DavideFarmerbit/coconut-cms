<?php

namespace XyloIsCoding\CoconutCms\Tests\Integration;

use Doctrine\DBAL\Connection;
use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Finds where a field is stored, from the schemas stored now, for a test
 * reading rows by hand: columns are generated, so none can be written out.
 * Fields are named by the table their type's level uses, and a path through
 * Embeds: "scope_car", "chassis:grade".
 */
final readonly class StoredColumns
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * $sql with every "{table.path}" replaced by that field's column, as in
     * "SELECT {scope_car.name} FROM scope_car", every "{#table.path}" by what
     * rows owned there hold in owner_field, and every "{@table.path}" by the
     * table holding that Collection's items.
     */
    public function sql(string $sql): string {
        return (string) preg_replace_callback(
            '/\{([#@]?)([a-z0-9_]+)\.([A-Za-z0-9_:]+)\}/',
            fn (array $match): string => match ($match[1]) {
                '#' => $this->ownerField($match[2], $match[3]),
                '@' => $this->collectionTable($match[2], $match[3]),
                default => $this->column($match[2], $match[3]),
            },
            $sql,
        );
    }

    /** The column of the field at $path on $table's level. */
    public function column(string $table, string $path): string {
        return ColumnNames::flattened(array_map(static fn (array $field): string => $field['column'], $this->walk($table, $path)));
    }

    /** What rows owned at $path on $table's level hold in owner_field. */
    public function ownerField(string $table, string $path): string {
        return self::joinedIds($this->walk($table, $path));
    }

    /** What rows owned at $path by an entity of type $identifier hold in owner_field: for a field of any level. */
    public function ownerFieldOf(string $identifier, string $path): string {
        $schemas = $this->schemas();

        return self::joinedIds(self::walkFrom($schemas, $schemas[$identifier], $path));
    }

    /** The table holding the items of the Collection at $path on $table's level. */
    public function collectionTable(string $table, string $path): string {
        return ColumnNames::collectionTable($table, $this->column($table, $path));
    }

    /**
     * Each stored field along $path, the Embeds first.
     * @return list<array<string, mixed>>
     */
    private function walk(string $table, string $path): array {
        $schemas = $this->schemas();
        // A side table's flags have the columns of the level they belong to.
        $level = str_starts_with($table, ColumnNames::stateTable('')) ? substr($table, strlen(ColumnNames::stateTable(''))) : $table;
        $start = array_values(array_filter($schemas, static fn (array $schema): bool => $schema['table'] === $level))[0]
            ?? throw new LogicException(sprintf('No stored schema uses table "%s".', $level));

        return self::walkFrom($schemas, $start, $path);
    }

    /**
     * Every stored schema as plain data, keyed by identifier.
     * @return array<string, array<string, mixed>>
     */
    private function schemas(): array {
        $schemas = [];
        foreach ($this->connection->fetchFirstColumn('SELECT schema_json FROM entity_schemas') as $json) {
            $schema = json_decode($json, true);
            $schemas[$schema['identifier']] = $schema;
        }

        return $schemas;
    }

    /** @param list<array<string, mixed>> $fields */
    private static function joinedIds(array $fields): string {
        return implode(FieldPath::EMBED, array_map(static fn (array $field): string => $field['id'], $fields));
    }

    /**
     * @param array<string, array<string, mixed>> $schemas
     * @param array<string, mixed> $current
     * @return list<array<string, mixed>>
     */
    private static function walkFrom(array $schemas, array $current, string $path): array {
        $found = [];
        foreach (explode(FieldPath::EMBED, $path) as $name) {
            $field = self::fieldIn($schemas, $current, $name);
            $found[] = $field;
            $current = $schemas[$field['target'] ?? ''] ?? $current;
        }

        return $found;
    }

    /**
     * Field $name of $schema or of an ancestor: an Embed flattens its whole chain.
     * @param array<string, array<string, mixed>> $schemas
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function fieldIn(array $schemas, array $schema, string $name): array {
        for ($level = $schema; $level !== null; $level = $schemas[$level['parent'] ?? ''] ?? null) {
            if (isset($level['fields'][$name])) {
                return $level['fields'][$name];
            }
        }

        throw new LogicException(sprintf('%s has no field "%s".', $schema['identifier'], $name));
    }
}

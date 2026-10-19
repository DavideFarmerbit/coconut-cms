<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * Persists a native class's default values as of the last applied migration, so a
 * later compile can diff its current, freshly-reflected values against them to
 * detect drift. Reflection alone can only ever report the current state of the
 * code; it has no memory of what the database was actually migrated to.
 */
final class NativeSchemaSnapshotStore
{
    private const TABLE = 'native_schema_snapshots';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function createTable(): void {
        $table = new Table(self::TABLE);
        $table->addColumn('class_identifier', Types::STRING, ['length' => 255]);
        $table->addColumn('field_name', Types::STRING, ['length' => 255]);
        $table->addColumn('value_json', Types::TEXT);
        $table->setPrimaryKey(['class_identifier', 'field_name']);

        $this->connection->createSchemaManager()->createTable($table);
    }

    public function dropTableIfExists(): void {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE])) {
            $schemaManager->dropTable(self::TABLE);
        }
    }

    /**
     * Replaces whatever snapshot this identifier already had with $defaultValues,
     * as a single transaction.
     * @param array<string, mixed> $defaultValues
     */
    public function save(string $identifier, array $defaultValues): void {
        $this->connection->transactional(function (Connection $connection) use ($identifier, $defaultValues): void {
            $connection->delete(self::TABLE, ['class_identifier' => $identifier]);

            foreach ($defaultValues as $field => $value) {
                $connection->insert(self::TABLE, [
                    'class_identifier' => $identifier,
                    'field_name' => $field,
                    'value_json' => json_encode($value),
                ]);
            }
        });
    }

    /** @return array<string, mixed> */
    public function load(string $identifier): array {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT field_name, value_json FROM %s WHERE class_identifier = ?', self::TABLE),
            [$identifier],
        );

        $values = [];
        foreach ($rows as $row) {
            $values[$row['field_name']] = json_decode($row['value_json'], true);
        }

        return $values;
    }

    /**
     * Compares $currentDefaultValues against whatever was last saved for
     * $identifier.
     * @param array<string, mixed> $currentDefaultValues
     * @return array{added: string[], removed: string[], changed: string[]}
     */
    public function diff(string $identifier, array $currentDefaultValues): array {
        $stored = $this->load($identifier);

        $added = array_values(array_diff(array_keys($currentDefaultValues), array_keys($stored)));
        $removed = array_values(array_diff(array_keys($stored), array_keys($currentDefaultValues)));

        $changed = [];
        foreach ($currentDefaultValues as $field => $value) {
            if (array_key_exists($field, $stored) && $stored[$field] !== $value) {
                $changed[] = $field;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }
}

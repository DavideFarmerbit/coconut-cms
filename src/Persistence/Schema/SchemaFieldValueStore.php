<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;

/**
 * Stores and resolves field values for any identifier, native or editor-created
 * alike: there's no separate mechanism per source, because the requirement is
 * the same either way. A native identifier's values get written here by the
 * compiler, read off reflection once and never re-read live on every resolve().
 * An editor-created identifier's values get written here directly, by whatever
 * saved it. Either way, resolving a value and diffing against what was last
 * written work the same way, over the same table.
 */
final class SchemaFieldValueStore
{
    private const TABLE = 'schema_field_values';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function createTable(): void {
        $table = new Table(self::TABLE);
        $table->addColumn('identifier', Types::STRING, ['length' => 255]);
        $table->addColumn('field_name', Types::STRING, ['length' => 255]);
        $table->addColumn('value_json', Types::TEXT);
        $table->setPrimaryKey(['identifier', 'field_name']);

        $this->connection->createSchemaManager()->createTable($table);
    }

    public function dropTableIfExists(): void {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE])) {
            $schemaManager->dropTable(self::TABLE);
        }
    }

    /**
     * Replaces whatever values this identifier already had with $values, as a
     * single transaction.
     * @param array<string, mixed> $values
     */
    public function save(string $identifier, array $values): void {
        $this->connection->transactional(function (Connection $connection) use ($identifier, $values): void {
            $connection->delete(self::TABLE, ['identifier' => $identifier]);

            foreach ($values as $field => $value) {
                $connection->insert(self::TABLE, [
                    'identifier' => $identifier,
                    'field_name' => $field,
                    'value_json' => json_encode($value),
                ]);
            }
        });
    }

    /** @return array<string, mixed> */
    public function load(string $identifier): array {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT field_name, value_json FROM %s WHERE identifier = ?', self::TABLE),
            [$identifier],
        );

        $values = [];
        foreach ($rows as $row) {
            $values[$row['field_name']] = json_decode($row['value_json'], true);
        }

        return $values;
    }

    /**
     * Compares $currentValues against whatever was last saved for $identifier.
     * @param array<string, mixed> $currentValues
     * @return array{added: string[], removed: string[], changed: string[]}
     */
    public function diff(string $identifier, array $currentValues): array {
        $stored = $this->load($identifier);

        $added = array_values(array_diff(array_keys($currentValues), array_keys($stored)));
        $removed = array_values(array_diff(array_keys($stored), array_keys($currentValues)));

        $changed = [];
        foreach ($currentValues as $field => $value) {
            if (array_key_exists($field, $stored) && $stored[$field] !== $value) {
                $changed[] = $field;
            }
        }

        return ['added' => $added, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * Returns the first explicit value found for $field, checking $scopesInOrder
     * from most to least specific. Building that order (an owning field site
     * before its target type, a type before its own parent type, and so on) is
     * the caller's concern, not this store's: it only ever checks what it's told.
     * @param string[] $scopesInOrder
     */
    public function resolve(array $scopesInOrder, string $field): mixed {
        foreach ($scopesInOrder as $scope) {
            $values = $this->load($scope);
            if (array_key_exists($field, $values)) {
                return $values[$field];
            }
        }

        throw new InvalidArgumentException(sprintf(
            'None of [%s] has an explicit value for "%s".',
            implode(', ', $scopesInOrder),
            $field,
        ));
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * Stores field values for any identifier, native or editor-created alike, as
 * plain (identifier, FieldPath) -> value rows: there's no separate mechanism
 * per source, because the requirement is the same either way. A native
 * identifier's values get written here by the compiler, an editor-created
 * one's by whatever saved it. Knows nothing about schemas: finding a value's
 * effective value through parents and targets is FieldValueResolver's job.
 *
 * Remembers what it loaded for the rest of the request, so resolving a value
 * through many sources doesn't reload the same rows. Every write goes through
 * here and forgets what it overwrites, so what it remembers is never stale
 * for anything written through this same instance.
 */
final class SchemaFieldValueStore
{
    private const TABLE = 'schema_field_values';

    /** @var array<string, FieldValues> Keyed by identifier. */
    private array $loaded = [];

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
        $this->loaded = [];
    }

    /** Creates the table unless it exists: what a migration does on any database. */
    public function ensureTable(): void {
        if (!$this->connection->createSchemaManager()->tablesExist([self::TABLE])) {
            $this->createTable();
        }
    }

    public function dropTableIfExists(): void {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE])) {
            $schemaManager->dropTable(self::TABLE);
        }

        $this->loaded = [];
    }

    /**
     * Replaces whatever values this identifier already had with $values, as a
     * single transaction.
     */
    public function save(string $identifier, FieldValues $values): void {
        $this->saveAll([$identifier => $values]);
    }

    /**
     * save() for several identifiers at once, as a single transaction: either
     * every identifier's values are replaced, or none are.
     * @param array<string, FieldValues> $valuesByIdentifier Keyed by identifier.
     */
    public function saveAll(array $valuesByIdentifier): void {
        // Forgotten first, so even a write that fails part-way leaves nothing
        // remembered that the database might no longer hold.
        foreach (array_keys($valuesByIdentifier) as $identifier) {
            unset($this->loaded[$identifier]);
        }

        $this->connection->transactional(function (Connection $connection) use ($valuesByIdentifier): void {
            foreach ($valuesByIdentifier as $identifier => $values) {
                $connection->delete(self::TABLE, ['identifier' => $identifier]);

                foreach ($values->toArray() as $path => $value) {
                    $connection->insert(self::TABLE, [
                        'identifier' => $identifier,
                        'field_name' => $path,
                        'value_json' => json_encode($value),
                    ]);
                }
            }
        });
    }

    /**
     * Removes every value stored for each of $identifiers: what a migration
     * does for a type the code no longer has.
     * @param list<string> $identifiers
     */
    public function deleteAll(array $identifiers): void {
        foreach ($identifiers as $identifier) {
            unset($this->loaded[$identifier]);
            $this->connection->delete(self::TABLE, ['identifier' => $identifier]);
        }
    }

    /**
     * Forgets everything loaded so far: after a rollback, what was loaded
     * inside the transaction may no longer be what the database holds.
     */
    public function forgetLoaded(): void {
        $this->loaded = [];
    }

    public function load(string $identifier): FieldValues {
        if (array_key_exists($identifier, $this->loaded)) {
            return $this->loaded[$identifier];
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT field_name, value_json FROM %s WHERE identifier = ?', $this->connection->quoteSingleIdentifier(self::TABLE)),
            [$identifier],
        );

        $values = new FieldValuesBuilder();
        foreach ($rows as $row) {
            $values->set(FieldPath::fromString($row['field_name']), json_decode($row['value_json'], true));
        }

        return $this->loaded[$identifier] = $values->build();
    }

    /** $currentValues compared against whatever was last saved for $identifier. */
    public function diff(string $identifier, FieldValues $currentValues): FieldValuesDiff {
        return FieldValuesDiff::between($this->load($identifier), $currentValues);
    }
}

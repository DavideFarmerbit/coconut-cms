<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Registry;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * Persists compiled schemas, one row each: where it came from, the schema as
 * JSON, its own fingerprint, and the fingerprints of everything it was
 * compiled against.
 * A schema is always read whole, never queried by parts.
 *
 * Remembers what it loaded for the rest of the request, misses included, and
 * forgets whatever a save overwrites, the same way SchemaFieldValueStore does.
 */
final class SchemaStore
{
    private const TABLE = 'entity_schemas';

    /** @var array<string, StoredSchema|null> Keyed by identifier. */
    private array $loaded = [];

    /** @var list<string>|null Every stored identifier, once listed; null until then. */
    private ?array $identifiers = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntitySchemaCodec $codec,
    ) {
    }

    public function createTable(): void {
        $table = new Table(self::TABLE);
        $table->addColumn('identifier', Types::STRING, ['length' => 255]);
        $table->addColumn('source', Types::STRING, ['length' => 16]);
        $table->addColumn('schema_json', Types::TEXT);
        $table->addColumn('fingerprint', Types::STRING, ['length' => 64]);
        $table->addColumn('dependencies_json', Types::TEXT);
        $table->setPrimaryKey(['identifier']);

        $this->connection->createSchemaManager()->createTable($table);
        $this->forgetLoaded();
    }

    /** Creates the table unless it exists: what a migration does on any database. */
    public function ensureTable(): void {
        if (!$this->tableExists()) {
            $this->createTable();
        }
    }

    /** Whether the table exists: on a database no migration ever ran on, it doesn't. */
    public function tableExists(): bool {
        return $this->connection->createSchemaManager()->tablesExist([self::TABLE]);
    }

    public function dropTableIfExists(): void {
        $schemaManager = $this->connection->createSchemaManager();
        if ($schemaManager->tablesExist([self::TABLE])) {
            $schemaManager->dropTable(self::TABLE);
        }

        $this->forgetLoaded();
    }

    /**
     * Runs $work in one transaction on this store's connection: how the
     * compiler commits schemas and values together. Atomic across both only
     * when SchemaFieldValueStore uses the same connection.
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function transactional(Closure $work): mixed {
        return $this->connection->transactional(static fn (): mixed => $work());
    }

    /**
     * Replaces whatever was stored for each of $schemas' identifiers, as one
     * transaction.
     * @param list<StoredSchema> $schemas
     */
    public function saveAll(array $schemas): void {
        // Forgotten first, so even a write that fails part-way leaves nothing
        // remembered that the database might no longer hold.
        foreach ($schemas as $stored) {
            unset($this->loaded[$stored->schema->identifier]);
        }
        $this->identifiers = null;

        $this->connection->transactional(function (Connection $connection) use ($schemas): void {
            foreach ($schemas as $stored) {
                $connection->delete(self::TABLE, ['identifier' => $stored->schema->identifier]);
                $connection->insert(self::TABLE, [
                    'identifier' => $stored->schema->identifier,
                    'source' => $stored->source->value,
                    'schema_json' => json_encode($this->codec->encode($stored->schema)),
                    'fingerprint' => $stored->fingerprint,
                    'dependencies_json' => json_encode($stored->dependencies),
                ]);
            }
        });
    }

    /**
     * Removes each of $identifiers' stored schema: what a migration does for
     * a type the code no longer has.
     * @param list<string> $identifiers
     */
    public function deleteAll(array $identifiers): void {
        $this->identifiers = null;
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
        $this->identifiers = null;
    }

    /** Null when nothing is stored for $identifier. */
    public function load(string $identifier): ?StoredSchema {
        if (array_key_exists($identifier, $this->loaded)) {
            return $this->loaded[$identifier];
        }

        $row = $this->connection->fetchAssociative(
            sprintf('SELECT source, schema_json, fingerprint, dependencies_json FROM %s WHERE identifier = ?', $this->connection->quoteSingleIdentifier(self::TABLE)),
            [$identifier],
        );

        return $this->loaded[$identifier] = $row === false ? null : new StoredSchema(
            $this->codec->decode(json_decode($row['schema_json'], true)),
            SchemaSource::from($row['source']),
            $row['fingerprint'],
            json_decode($row['dependencies_json'], true),
        );
    }

    /**
     * Every stored schema, of every source: what a migration compares with
     * the code.
     * @return list<StoredSchema>
     */
    public function all(): array {
        return array_map(fn (string $identifier): StoredSchema => $this->load($identifier), $this->identifiers());
    }

    /**
     * Every stored identifier, of every source, in order: remembered like a
     * load.
     * @return list<string>
     */
    public function identifiers(): array {
        return $this->identifiers ??= $this->connection->fetchFirstColumn(sprintf('SELECT identifier FROM %s ORDER BY identifier', $this->connection->quoteSingleIdentifier(self::TABLE)));
    }

    /**
     * $schema's own fingerprint, given the values stored for it: changes
     * whenever either does, and only then. Never includes a dependency's.
     */
    public function fingerprintOf(EntitySchema $schema, FieldValues $values): string {
        $byPath = $values->toArray();
        ksort($byPath, SORT_STRING);

        return hash('sha256', json_encode([$this->codec->encode($schema), $byPath]));
    }
}

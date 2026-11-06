<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelChains;
use XyloIsCoding\CoconutCms\Persistence\Entity\PendingConversion;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\StoredEntity;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateConversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\CompiledBatch;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;

/**
 * A running change to stored content: the tables are expanded, and aggregates
 * move to the new shape one at a time. The old schemas stay stored meanwhile.
 *
 * Its marker table holds, per aggregate, the token of the write that left it
 * in the new shape. An aggregate is left behind when it has no marker, or when
 * any later save, by a release knowing nothing of this, gave its root a new
 * token. A gained level needs nothing more: an aggregate with no row there
 * has never been marked.
 */
final class ContentChange implements PendingConversion
{
    private const MARKER_PREFIX = '_seen__';

    private readonly AggregateReader $reader;
    private readonly AggregateConversion $conversion;
    private readonly LevelChains $oldChains;
    private readonly LevelChains $newChains;
    private readonly SchemaLookup $oldSchemas;

    /**
     * @param string $id Names its marker table: letters, digits and "_".
     * @param MigrationPlan $plan What brings the old shape to the new one.
     * @param CompiledBatch $batch The new shape, identified.
     * @param list<string> $types Every stored type whose aggregates it changes.
     * @param SchemaRegistry $registry Serves the old schemas: what's stored.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $id,
        MigrationPlan $plan,
        private readonly CompiledBatch $batch,
        private readonly array $types,
        SchemaRegistry $registry,
        SchemaFieldValueStore $store,
    ) {
        $this->reader = new AggregateReader($connection, $registry);
        $this->conversion = new AggregateConversion($plan, [...$plan->schemas->schemas(), ...$batch->schemas], $batch->values, $registry, $store);
        $this->oldSchemas = new SchemaLookup($registry);
        $this->oldChains = new LevelChains($this->oldSchemas);
        $this->newChains = new LevelChains($this->conversion->schemas());
    }

    /** Creates its marker table. Part of expand: nothing else touches a table. */
    public function createMarkers(): void {
        $table = new Table($this->markerTable());
        $table->addColumn('id', Types::GUID);
        $table->addColumn('seen', Types::GUID);
        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint(DatabaseSchema::ENTITIES, ['id'], ['id'], ['onDelete' => 'CASCADE']);
        $this->connection->createSchemaManager()->createTable($table);
    }

    public function schemas(): array {
        return $this->batch->schemas;
    }

    public function defaults(): array {
        return $this->batch->values;
    }

    /** Asks only about what can be left behind: an aggregate holding none of its types never is. */
    public function isLeftBehind(string $id): bool {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT e.written, m.seen FROM %s e LEFT JOIN %s m ON m.id = e.id WHERE e.id = ?',
                $this->quoted(DatabaseSchema::ENTITIES),
                $this->quoted($this->markerTable()),
            ),
            [$id],
        );

        if ($row === false || $row['written'] === $row['seen']) {
            return false;
        }

        return $this->holdsChangedType($id);
    }

    /** Whether the aggregate rooted at $id holds one of its types, at any depth. */
    private function holdsChangedType(string $root): bool {
        return $this->connection->fetchOne(sprintf(
            'WITH RECURSIVE down (id, concrete_identifier) AS ('
            . ' SELECT id, concrete_identifier FROM %1$s WHERE id = ?'
            . ' UNION'
            . ' SELECT owned.id, owned.concrete_identifier FROM %1$s owned JOIN down ON owned.owner = down.id'
            . " WHERE owned.owner_field <> ''"
            . ') SELECT 1 FROM down WHERE concrete_identifier IN (?) LIMIT 1',
            $this->quoted(DatabaseSchema::ENTITIES),
        ), [$root, $this->types], [ParameterType::STRING, ArrayParameterType::STRING]) !== false;
    }

    public function converted(string $id): ?StoredEntity {
        $aggregate = $this->reader->read($id);
        $values = $aggregate === null ? null : $this->conversion->convert($aggregate);
        if ($values === null) {
            return null;
        }

        // An object the new default replaces with another class is another
        // object: it has no rows yet.
        $ownedIds = [];
        foreach ($aggregate->ownedIds() as $path => $ownedId) {
            $at = FieldPath::fromString((string) $path);
            if ($values->dense->has($at) && $values->dense->get($at) === $aggregate->get($at)) {
                $ownedIds[(string) $path] = $ownedId;
            }
        }

        return new StoredEntity($values->identifier, $values->dense, $values->touched, $ownedIds);
    }

    public function tablesOf(string $identifier): array {
        $tables = [];
        foreach ([[$this->oldChains, $this->oldSchemas], [$this->newChains, $this->conversion->schemas()]] as [$chains, $schemas]) {
            if ($schemas->has($identifier)) {
                foreach ($chains->of($identifier) as $level) {
                    $tables[$level->table] = true;
                }
            }
        }

        return array_map('strval', array_keys($tables));
    }

    public function mark(string $id, string $written): void {
        $table = $this->quoted($this->markerTable());
        $this->connection->delete($table, ['id' => $id]);
        $this->connection->insert($table, ['id' => $id, 'seen' => $written]);
    }

    private function markerTable(): string {
        return self::MARKER_PREFIX . $this->id;
    }

    private function quoted(string $name): string {
        return $this->connection->quoteSingleIdentifier($name);
    }
}

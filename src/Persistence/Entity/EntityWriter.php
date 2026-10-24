<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelChains;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRows;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRowsBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\Placement;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\Row;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Writes entities, native or editor-created alike, by id: the counterpart of
 * EntityReader. Works on whole aggregates: an entity together with everything
 * it owns, all in one transaction. An owned entity is only ever written or
 * deleted through its owner, and belongs to one aggregate at a time. Writes
 * and deletes of one aggregate run one at a time: each locks its root first.
 *
 * Writing reconciles what's stored with what's wanted now. An owned entity no
 * longer wanted is deleted, along with everything it owns: one replaced by
 * another, one dropped from an Owned Collection, one back at its defaults.
 * Every other row is rewritten whole. A Reference in the aggregate to an
 * entity the write deletes is written null, the way a Reference from
 * anywhere else is nulled by its foreign key.
 *
 * Refuses to write while a migration is unfinished, except for the
 * migration's own writes.
 */
final class EntityWriter
{
    private readonly EntityRowsBuilder $rows;
    private readonly LevelChains $storedChains;

    /**
     * @param SchemaLookup $schemas The shape rows are written in.
     * @param SchemaLookup $storedSchemas The shape stored rows are in: where
     *        an entity's old rows are deleted from.
     * @param MigrationMarker|null $migration Checked before each write and
     *        delete; null for the migration's own writer.
     */
    private function __construct(
        private readonly Connection $connection,
        SchemaLookup $schemas,
        SchemaLookup $storedSchemas,
        private readonly ?MigrationMarker $migration,
    ) {
        $this->rows = new EntityRowsBuilder($schemas);
        $this->storedChains = new LevelChains($storedSchemas);
    }

    /** Writes and deletes with the schemas $registry serves: what every request does. */
    public static function of(Connection $connection, SchemaRegistry $registry): self {
        $schemas = new SchemaLookup($registry);

        return new self($connection, $schemas, $schemas, new MigrationMarker($connection));
    }

    /**
     * For a migration's move: rows are written in $target's shape, while the
     * rows they replace are still in $stored's, a renamed type's old tables
     * included.
     */
    public static function forMigration(Connection $connection, SchemaLookup $target, SchemaLookup $stored): self {
        return new self($connection, $target, $stored, null);
    }

    /**
     * Stores $values as entity $id, a new one or one stored before.
     * @param Closure(FieldPath): string $ownedIdOf The id of the object owned
     *        at a path from this entity: the one it had, or a new one.
     * @return list<string> The id of every owned entity it deleted.
     */
    public function write(EntityValues $values, string $id, Closure $ownedIdOf): array {
        return $this->transactional(function () use ($values, $id, $ownedIdOf): array {
            $stored = $this->lockedAggregateOf($id);
            $wanted = [];
            foreach ($this->rows->rowsOf($values, $id, Placement::standalone(), $ownedIdOf) as $entity) {
                $wanted[$entity->id] = $entity;
            }

            $this->assertNoneStoredElsewhere(array_keys(array_diff_key($wanted, $stored)));

            // Kept entities lose their content rows first: rewriting them whole
            // never trips a unique index on a value about to move elsewhere.
            foreach (array_intersect_key($stored, $wanted) as $keptId => $entity) {
                $this->deleteContentRows($keptId, $entity['concrete_identifier']);
            }

            // Owners before what they own: an entity moved to a new owner
            // must stop pointing at its old one before that one is deleted.
            foreach ($wanted as $wantedId => $entity) {
                $this->writeEntitiesRow($entity, isset($stored[$wantedId]));
            }

            $deleted = $this->deleteDeepestFirst(array_diff_key($stored, $wanted));

            // A Reference to an entity just deleted is null, as its foreign
            // key would have made it, had its row existed already.
            foreach ($wanted as $entity) {
                foreach ($entity->contentRows() as $row) {
                    $this->insert($row->withoutReferencesTo($deleted));
                }
            }

            return $deleted;
        });
    }

    /**
     * Deletes entity $id and everything it owns. Nothing happens when it was
     * never stored.
     * @return list<string> The id of every entity deleted, $id included.
     */
    public function delete(string $id): array {
        return $this->transactional(fn (): array => $this->deleteDeepestFirst($this->lockedAggregateOf($id)));
    }

    /**
     * Runs $work in a transaction, read committed when it's the outermost
     * one: locking an id not stored yet then locks nothing else, and every
     * read after a lock sees what the save holding it committed. Throws
     * first while a migration is unfinished.
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    private function transactional(Closure $work): mixed {
        $this->migration?->assertSettled();

        if (!$this->connection->isTransactionActive()) {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        return $this->connection->transactional($work);
    }

    /**
     * Locks entity $id's row until the transaction ends, then reads it and
     * everything stored as owned by it, at any depth. Every write and delete
     * of an aggregate locks its root first, so two never run at once. Empty
     * when $id was never stored. Throws when $id is itself owned.
     * @return array<string, array{concrete_identifier: string, depth: int}> Keyed by id.
     */
    private function lockedAggregateOf(string $id): array {
        $this->connection->fetchOne(sprintf('SELECT id FROM %s WHERE id = ? FOR UPDATE', DatabaseSchema::ENTITIES), [$id]);

        // A standalone entity is owned by the Cms row through no field: only
        // a row with an owner field counts as owned.
        $rows = $this->connection->fetchAllAssociative(sprintf(
            'WITH RECURSIVE aggregate (id, concrete_identifier, owner_field, depth) AS ('
            . ' SELECT id, concrete_identifier, owner_field, 0 FROM %1$s WHERE id = ?'
            . ' UNION ALL'
            . ' SELECT owned.id, owned.concrete_identifier, owned.owner_field, aggregate.depth + 1'
            . ' FROM %1$s owned JOIN aggregate ON owned.owner = aggregate.id'
            . " WHERE owned.owner_field <> ''"
            . ') SELECT id, concrete_identifier, owner_field, depth FROM aggregate',
            DatabaseSchema::ENTITIES,
        ), [$id]);

        $aggregate = [];
        foreach ($rows as $row) {
            if ($row['id'] === $id && $row['owner_field'] !== '') {
                throw new LogicException(sprintf('Entity %s is owned by another entity: write or delete it through its owner.', $id));
            }

            $aggregate[$row['id']] = ['concrete_identifier' => $row['concrete_identifier'], 'depth' => (int) $row['depth']];
        }

        return $aggregate;
    }

    /**
     * Throws when any of $ids, about to be inserted, is already stored: an
     * owned object put in a second aggregate while still stored in its first.
     * @param list<string> $ids
     */
    private function assertNoneStoredElsewhere(array $ids): void {
        if ($ids === []) {
            return;
        }

        $found = $this->connection->fetchFirstColumn(
            sprintf('SELECT id FROM %s WHERE id IN (?)', DatabaseSchema::ENTITIES),
            [$ids],
            [ArrayParameterType::STRING],
        );

        if ($found !== []) {
            throw new LogicException(sprintf(
                'Entity %s is already stored in another aggregate: an owned object belongs to one owner. Save the aggregate it left first, or put a new object here.',
                $found[0],
            ));
        }
    }

    /** Inserts $entity's entities row, or updates it when $exists. */
    private function writeEntitiesRow(EntityRows $entity, bool $exists): void {
        $row = $entity->entitiesRow();
        if (!$exists) {
            $this->insert($row);

            return;
        }

        $values = $row->values;
        unset($values['id']);
        $update = new Row($row->table, $values);
        $this->connection->update($update->table, $update->values, ['id' => $entity->id], $update->types());
    }

    /**
     * Deletes entity $id's row on every level of $identifier's chain: its
     * side table and Collection rows go with it.
     */
    private function deleteContentRows(string $id, string $identifier): void {
        foreach ($this->storedChains->of($identifier) as $level) {
            $this->connection->delete($level->table, [ColumnNames::ID => $id]);
        }
    }

    /**
     * Deletes every entity in $entities, deepest first: an owner can only go
     * once nothing points to it anymore. Its content rows go with it.
     * @param array<string, array{concrete_identifier: string, depth: int}> $entities Keyed by id.
     * @return list<string> Their ids.
     */
    private function deleteDeepestFirst(array $entities): array {
        uasort($entities, static fn (array $a, array $b): int => $b['depth'] <=> $a['depth']);

        foreach (array_keys($entities) as $id) {
            $this->connection->delete(DatabaseSchema::ENTITIES, ['id' => $id]);
        }

        return array_keys($entities);
    }

    private function insert(Row $row): void {
        $this->connection->insert($row->table, $row->values, $row->types());
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use Closure;
use Doctrine\DBAL\Connection;
use LogicException;
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
 * deleted through its owner.
 *
 * Writing reconciles what's stored with what's wanted now. An owned entity no
 * longer wanted is deleted, along with everything it owns: one replaced by
 * another, one dropped from an Owned Collection, one back at its defaults.
 * Every other row is rewritten whole.
 */
final class EntityWriter
{
    private readonly EntityRowsBuilder $rows;
    private readonly LevelChains $chains;

    public function __construct(
        private readonly Connection $connection,
        SchemaRegistry $registry,
    ) {
        $schemas = new SchemaLookup($registry);
        $this->rows = new EntityRowsBuilder($schemas);
        $this->chains = new LevelChains($schemas);
    }

    /**
     * Stores $values as entity $id, a new one or one stored before.
     * @param Closure(FieldPath): string $ownedIdOf The id of the object owned
     *        at a path from this entity: the one it had, or a new one.
     * @return list<string> The id of every owned entity it deleted.
     */
    public function write(EntityValues $values, string $id, Closure $ownedIdOf): array {
        return $this->connection->transactional(function () use ($values, $id, $ownedIdOf): array {
            $stored = $this->storedAggregateOf($id);
            $wanted = [];
            foreach ($this->rows->rowsOf($values, $id, Placement::standalone(), $ownedIdOf) as $entity) {
                $wanted[$entity->id] = $entity;
            }

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

            foreach ($wanted as $entity) {
                foreach ($entity->contentRows() as $row) {
                    $this->insert($row);
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
        return $this->connection->transactional(fn (): array => $this->deleteDeepestFirst($this->storedAggregateOf($id)));
    }

    /**
     * Entity $id and everything stored as owned by it, at any depth: empty
     * when it was never stored. Throws when $id is itself owned.
     * @return array<string, array{concrete_identifier: string, depth: int}> Keyed by id.
     */
    private function storedAggregateOf(string $id): array {
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
        foreach ($this->chains->of($identifier) as $level) {
            $this->connection->delete($level->table, ['id' => $id]);
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

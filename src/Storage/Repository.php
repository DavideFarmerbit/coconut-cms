<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Connection;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;

/**
 * Reads and writes instances of one native entity class from one table, going through
 * the IdentityMap so the same row is never hydrated twice, and through FieldValidator
 * plus the friendly uniqueness pre-check before anything is written.
 *
 * Update takes a plain field => value array of changes for now, a stand-in for the
 * real Changeset write path that Phase 3 replaces this with.
 */
final class Repository
{
    /** @var FieldDescriptor[] */
    private readonly array $fields;

    /** @param class-string $class the entity class this repository reads and writes */
    public function __construct(
        private readonly Connection $connection,
        private readonly IdentityMap $identityMap,
        private readonly string $class,
        private readonly string $table,
    ) {
        $this->fields = PrototypeShape::ofClass($this->class);
    }

    public function find(string $id): ?object
    {
        $cached = $this->identityMap->get($this->class, $id);
        if ($cached !== null) {
            return $cached;
        }

        $row = $this->connection->fetchAssociative(
            sprintf('SELECT * FROM %s WHERE %s = ?', $this->table, SchemaBuilder::ID_COLUMN),
            [$id],
        );

        if ($row === false) {
            return null;
        }

        $entity = (new ReflectionClass($this->class))->newInstanceArgs(RowMapper::fromRow($this->fields, $row));
        $this->identityMap->put($this->class, $id, $entity);

        return $entity;
    }

    /** @return string the new entity's id */
    public function insert(object $entity): string
    {
        $values = RowMapper::propertiesOf($entity, $this->fields);
        $this->validate($values);
        $this->assertUnique($values, null);

        $this->connection->insert($this->table, RowMapper::toRow($this->fields, $values));
        $id = (string) $this->connection->lastInsertId();

        $this->identityMap->put($this->class, $id, $entity);

        return $id;
    }

    /**
     * @param array<string, mixed> $changes field name => new value, only the fields being changed
     * @return object the entity's new state
     */
    public function update(string $id, array $changes): object
    {
        $current = $this->find($id);
        if ($current === null) {
            throw new ValidationException(sprintf('Cannot update %s #%s, it does not exist.', $this->class, $id));
        }

        $values = [...RowMapper::propertiesOf($current, $this->fields), ...$changes];
        $this->validate($values);
        $this->assertUnique($values, $id);

        $this->connection->update($this->table, RowMapper::toRow($this->fields, $values), [SchemaBuilder::ID_COLUMN => $id]);

        $entity = (new ReflectionClass($this->class))->newInstanceArgs($values);
        $this->identityMap->put($this->class, $id, $entity);

        return $entity;
    }

    public function delete(string $id): void
    {
        $this->connection->delete($this->table, [SchemaBuilder::ID_COLUMN => $id]);
        $this->identityMap->forget($this->class, $id);
    }

    /** @param array<string, mixed> $values */
    private function validate(array $values): void
    {
        foreach ($this->fields as $field) {
            foreach ($field->validators as $validator) {
                if (!$validator->validate($values[$field->name] ?? null)) {
                    throw new ValidationException(sprintf('Field "%s" is invalid.', $field->name));
                }
            }
        }
    }

    /**
     * The friendly half of uniqueness enforcement, the real UNIQUE constraint
     * SchemaBuilder already put on the column is the authoritative backstop.
     *
     * @param array<string, mixed> $values
     */
    private function assertUnique(array $values, ?string $excludingId): void
    {
        foreach ($this->fields as $field) {
            if (!$field->unique) {
                continue;
            }

            $sql = sprintf('SELECT 1 FROM %s WHERE %s = ?', $this->table, $field->name);
            $params = [$values[$field->name]];

            if ($excludingId !== null) {
                $sql .= sprintf(' AND %s != ?', SchemaBuilder::ID_COLUMN);
                $params[] = $excludingId;
            }

            if ($this->connection->fetchOne($sql, $params) !== false) {
                throw new ValidationException(sprintf('"%s" is already taken for field "%s".', $values[$field->name], $field->name));
            }
        }
    }
}

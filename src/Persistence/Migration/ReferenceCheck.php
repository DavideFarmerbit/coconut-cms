<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use RuntimeException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ValueColumn;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;

/**
 * Checks, once a migration has moved every aggregate, that every Reference
 * points at its target type or one extending it. The foreign key already
 * says the entity exists; nothing in the database says its type. Checked
 * after the move, when every entity has its new identifier: one query per
 * Reference column.
 */
final readonly class ReferenceCheck
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Throws naming the first Reference that points at the wrong type.
     * @param list<LevelLayout> $levels Every level of the new shape.
     * @param array<string, EntitySchema> $schemas Every schema of the new shape, keyed by identifier.
     */
    public function assertTargetsMatch(array $levels, array $schemas): void {
        foreach ($levels as $level) {
            foreach ($level->columns as $column) {
                $this->assertColumn($level->table, ColumnNames::ID, $column, $schemas);
            }

            foreach ($level->collections as $collection) {
                foreach ($collection->itemColumns as $column) {
                    $this->assertColumn($collection->table, ColumnNames::OWNER, $column, $schemas, (string) $collection->path);
                }
            }
        }
    }

    /**
     * @param string $idColumn The column naming the entity the row belongs to.
     * @param array<string, EntitySchema> $schemas
     * @param string|null $collectionPath The Collection the column's table holds, if it does.
     */
    private function assertColumn(string $table, string $idColumn, ValueColumn $column, array $schemas, ?string $collectionPath = null): void {
        if ($column->field->kind() !== FieldKind::Reference) {
            return;
        }

        $target = $column->field->target();
        $wrong = $this->connection->fetchAssociative(
            sprintf(
                'SELECT holder.%s AS entity, referenced.id AS referenced, referenced.concrete_identifier AS type FROM %s holder JOIN %s referenced ON referenced.id = holder.%s WHERE referenced.concrete_identifier NOT IN (?) LIMIT 1',
                $this->connection->quoteSingleIdentifier($idColumn),
                $this->connection->quoteSingleIdentifier($table),
                $this->connection->quoteSingleIdentifier(DatabaseSchema::ENTITIES),
                $this->connection->quoteSingleIdentifier($column->name),
            ),
            [self::targetAndDescendants($target, $schemas)],
            [ArrayParameterType::STRING],
        );

        if ($wrong !== false) {
            $path = $collectionPath === null ? (string) $column->path : $collectionPath . ($column->path === null ? '' : '.' . $column->path);
            throw new RuntimeException(sprintf(
                'Entity %s: "%s" points at %s, a "%s", which is neither "%s" nor a type extending it.',
                $wrong['entity'],
                $path,
                $wrong['referenced'],
                $wrong['type'],
                $target,
            ));
        }
    }

    /**
     * @param array<string, EntitySchema> $schemas
     * @return list<string>
     */
    private static function targetAndDescendants(string $target, array $schemas): array {
        $types = [];
        foreach ($schemas as $identifier => $schema) {
            for ($current = $identifier; $current !== null; $current = $schemas[$current]->parent ?? null) {
                if ($current === $target) {
                    $types[] = $identifier;
                    break;
                }
            }
        }

        return $types;
    }
}

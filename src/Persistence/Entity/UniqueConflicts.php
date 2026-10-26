<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelChains;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ValueColumn;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRows;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\Row;

/**
 * Which unique groups a save broke, once the database refused it: so the
 * error names fields, not an index. The database decided; this only finds
 * where. A group is broken when another entity's stored values match the
 * saved ones, the aggregate's own left out, or when two rows of the save
 * itself match each other. A group holding a null never matches: a unique
 * index ignores nulls.
 */
final readonly class UniqueConflicts
{
    public function __construct(
        private Connection $connection,
        private LevelChains $chains,
    ) {
    }

    /**
     * @param list<EntityRows> $wanted Every entity the save wrote.
     * @param array<string, string> $pathsById Each written entity's path in
     *        the aggregate, by id: "" for the root, "engine", "spares.1".
     * @param list<string> $ownIds Every entity of the aggregate, stored or wanted:
     *        none of them conflicts with the save.
     * @return array<string, list<string>> Each field's errors, by path.
     */
    public function of(array $wanted, array $pathsById, array $ownIds): array {
        $errors = [];
        $seen = [];

        foreach ($wanted as $entity) {
            $prefix = $pathsById[$entity->id] ?? '';
            foreach ($this->chains->of($entity->identifier) as $level) {
                $row = $this->rowIn($entity, $level->table);
                if ($row === null) {
                    continue;
                }

                foreach ($level->uniqueIndexes as $columns) {
                    $values = self::valuesOf($row, $columns);
                    if ($values === null) {
                        continue;
                    }

                    // Two entities of this save holding the same values.
                    $key = $level->table . json_encode($values);
                    $isDuplicate = isset($seen[$key]);
                    $seen[$key] = true;

                    if ($isDuplicate || $this->takenElsewhere($level->table, $values, $ownIds)) {
                        foreach (self::fieldColumns($level->columns, $columns) as $column) {
                            $errors[self::join($prefix, (string) $column->path)][] = self::message($level->columns, $columns);
                        }
                    }
                }

                foreach ($level->collections as $collection) {
                    foreach ($collection->uniqueIndexes as $columns) {
                        $itemsSeen = [];
                        foreach ($entity->contentRows() as $itemRow) {
                            if ($itemRow->table !== $collection->table) {
                                continue;
                            }

                            $values = self::valuesOf($itemRow, $columns);
                            $key = json_encode($values);
                            if ($values !== null && isset($itemsSeen[$key])) {
                                foreach (self::fieldColumns($collection->itemColumns, $columns) as $column) {
                                    $item = self::join(self::join($prefix, (string) $collection->path), (string) $itemRow->values[ColumnNames::POSITION]);
                                    $errors[$column->path === null ? $item : $item . '.' . $column->path][] = self::message($collection->itemColumns, $columns);
                                }
                            }
                            $itemsSeen[$key] = true;
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /** The row $entity writes into $table, null when it writes none. */
    private function rowIn(EntityRows $entity, string $table): ?Row {
        foreach ($entity->contentRows() as $row) {
            if ($row->table === $table) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Whether an entity outside $ownIds stores $values in $table.
     * @param array<string, mixed> $values By column.
     * @param list<string> $ownIds
     */
    private function takenElsewhere(string $table, array $values, array $ownIds): bool {
        $builder = $this->connection->createQueryBuilder()
            ->select('1')
            ->from($this->connection->quoteSingleIdentifier($table))
            ->where(sprintf('%s NOT IN (%s)', $this->connection->quoteSingleIdentifier(ColumnNames::ID), '?'))
            ->setMaxResults(1);
        $parameters = [$ownIds];
        $types = [ArrayParameterType::STRING];

        foreach ($values as $column => $value) {
            $builder->andWhere(sprintf('%s = ?', $this->connection->quoteSingleIdentifier($column)));
            $parameters[] = $value;
            $types[] = (new Row($table, [$column => $value]))->types()[$column];
        }

        return $this->connection->fetchOne($builder->getSQL(), $parameters, $types) !== false;
    }

    /**
     * $row's values for $columns, null when one is null: it can't conflict.
     * @param list<string> $columns
     * @return array<string, mixed>|null
     */
    private static function valuesOf(Row $row, array $columns): ?array {
        $values = [];
        foreach ($columns as $column) {
            if (($row->values[$column] ?? null) === null) {
                return null;
            }
            $values[$column] = $row->values[$column];
        }

        return $values;
    }

    /**
     * The value columns among $columns: the ones a field holds, not the
     * owner the group is scoped to.
     * @param list<ValueColumn> $valueColumns
     * @param list<string> $columns
     * @return list<ValueColumn>
     */
    private static function fieldColumns(array $valueColumns, array $columns): array {
        return array_values(array_filter($valueColumns, static fn (ValueColumn $column): bool => in_array($column->name, $columns, true)));
    }

    /**
     * @param list<ValueColumn> $valueColumns
     * @param list<string> $columns
     */
    private static function message(array $valueColumns, array $columns): string {
        $paths = array_map(static fn (ValueColumn $column): string => $column->path === null ? 'value' : (string) $column->path, self::fieldColumns($valueColumns, $columns));

        return count($paths) === 1
            ? 'must be unique: another entity already has it'
            : sprintf('must be unique together with %s: another entity already has them', implode(', ', $paths));
    }

    private static function join(string $prefix, string $path): string {
        return $prefix === '' ? $path : $prefix . '.' . $path;
    }
}

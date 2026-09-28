<?php

namespace XyloIsCoding\CoconutCms\Storage\Query;

use Doctrine\DBAL\Connection;
use LogicException;
use XyloIsCoding\CoconutCms\Storage\EntityManager;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\SchemaBuilder;

/**
 * Filters, sorts, and cursor-paginates $identifier's own chain (base through itself),
 * never sideways across sibling subclasses or down into further subclasses of it, the
 * same per-identifier scope every other content type in this design already has.
 *
 * Resolves a field to its real column and which chain-level table declares it, then
 * joins every level once with per-level column aliasing, one query instead of
 * Repository::find()'s one-query-per-level approach, which would be an N+1 disaster
 * for a list of rows. Each result row is handed to that level's own Repository to
 * finish hydrating (Repository::materialize()), so a Query result behaves exactly
 * like a direct find(), same Identity Map, same lazy references.
 *
 * Cursor/keyset pagination only, per ARCHITECTURE.md, never OFFSET: the sort field's
 * value plus the id tiebreaker, a portable OR-chain WHERE rather than a row-value
 * comparison. Only a queryable scalar/Choice/EntityReference field declared directly
 * on the chain can be filtered or sorted by, not a blob-only field, an embed's own
 * nested field, or a collection, same "stays out of scope" as everywhere else in this
 * design ("Admin list/filter views").
 */
final class Query
{
    private const array OPERATORS = ['=', '!=', '>', '>=', '<', '<='];

    /** @var array{0: string, 1: string, 2: mixed}[] field, operator, value */
    private array $wheres = [];
    private ?string $sortField = null;
    private string $sortDirection = 'ASC';
    private ?Cursor $cursor = null;
    private int $limitCount = 50;

    /** @var string[] base first, ending with $identifier itself */
    private readonly array $chain;

    /** @var array<string, FieldDescriptor[]> each chain level's own fields */
    private readonly array $ownFieldsByLevel;

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManager $entityManager,
        private readonly string $identifier,
    ) {
        $this->chain = $this->entityManager->chainOf($identifier);

        $ownFieldsByLevel = [];
        foreach ($this->chain as $level) {
            $ownFieldsByLevel[$level] = $this->entityManager->ownFieldsOf($level);
        }
        $this->ownFieldsByLevel = $ownFieldsByLevel;
    }

    public function where(string $field, string $operator, mixed $value): self
    {
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new LogicException(sprintf('Unsupported operator "%s".', $operator));
        }

        $this->wheres[] = [$field, $operator, $value];

        return $this;
    }

    public function orderBy(string $field, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if ($direction !== 'ASC' && $direction !== 'DESC') {
            throw new LogicException(sprintf('Unsupported sort direction "%s".', $direction));
        }

        $this->sortField = $field;
        $this->sortDirection = $direction;

        return $this;
    }

    /** Resumes after a page fetched previously, must match whatever orderBy() (or its id-only default) was in effect when the cursor was produced. */
    public function after(Cursor $cursor): self
    {
        $this->cursor = $cursor;

        return $this;
    }

    public function limit(int $count): self
    {
        $this->limitCount = $count;

        return $this;
    }

    public function get(): Page
    {
        [$filterSql, $filterParams] = $this->filterSql();
        [$cursorSql, $cursorParams] = $this->cursorSql();

        $whereParts = array_values(array_filter([$filterSql, $cursorSql], static fn (string $sql): bool => $sql !== ''));
        $whereSql = $whereParts === [] ? '' : 'WHERE ' . implode(' AND ', $whereParts);

        $sql = sprintf(
            'SELECT %s FROM %s %s %s LIMIT %d',
            $this->selectList(),
            $this->fromClause(),
            $whereSql,
            $this->orderSql(),
            $this->limitCount + 1,
        );

        $rows = $this->connection->fetchAllNumeric($sql, [...$filterParams, ...$cursorParams]);

        $hasMore = count($rows) > $this->limitCount;
        $rows = array_slice($rows, 0, $this->limitCount);

        $columnNamesByLevel = $this->columnNamesByLevel();
        $repository = $this->entityManager->repository($this->identifier);

        $items = [];
        $lastId = null;
        $lastSortValue = null;
        foreach ($rows as $row) {
            [$id, $rowsByLevel] = $this->splitRow($row, $columnNamesByLevel);

            $items[] = $repository->materialize($id, $rowsByLevel);
            $lastId = $id;
            $lastSortValue = $this->sortField === null ? null : $this->sortValueFromRows($rowsByLevel);
        }

        $nextCursor = $hasMore && $lastId !== null ? new Cursor($lastSortValue, $lastId) : null;

        return new Page($items, $hasMore, $nextCursor);
    }

    /** The same WHERE filterSql() would use, independent of sorting/pagination, for a "showing X-Y of Z" display. */
    public function count(): int
    {
        [$filterSql, $filterParams] = $this->filterSql();
        $whereSql = $filterSql === '' ? '' : 'WHERE ' . $filterSql;

        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s %s', $this->fromClause(), $whereSql), $filterParams);
    }

    private function selectList(): string
    {
        return implode(', ', array_map(static fn (int $index): string => sprintf('t%d.*', $index), array_keys($this->chain)));
    }

    private function fromClause(): string
    {
        $sql = sprintf('%s AS t0', $this->entityManager->tableOf($this->chain[0]));

        for ($index = 1; $index < count($this->chain); $index++) {
            $sql .= sprintf(' JOIN %s AS t%d ON t%d.id = t0.id', $this->entityManager->tableOf($this->chain[$index]), $index, $index);
        }

        return $sql;
    }

    /** @return array{0: string, 1: mixed[]} SQL fragment (no leading WHERE/AND), its params */
    private function filterSql(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }

        $parts = [];
        $params = [];
        foreach ($this->wheres as [$fieldName, $operator, $value]) {
            [, , $field] = $this->locateField($fieldName);
            $parts[] = sprintf('%s %s ?', $this->columnRef($fieldName), $operator);
            $params[] = $field->kind === FieldKind::Bool ? (int) $value : $value;
        }

        return [implode(' AND ', $parts), $params];
    }

    /** @return array{0: string, 1: mixed[]} */
    private function cursorSql(): array
    {
        if ($this->cursor === null) {
            return ['', []];
        }

        $operator = $this->sortDirection === 'ASC' ? '>' : '<';

        if ($this->sortField === null) {
            return [sprintf('t0.id %s ?', $operator), [$this->cursor->id]];
        }

        $sortRef = $this->columnRef($this->sortField);
        [, , $field] = $this->locateField($this->sortField);
        $sortValue = $field->kind === FieldKind::Bool ? (int) $this->cursor->sortValue : $this->cursor->sortValue;

        return [
            sprintf('(%s %s ? OR (%s = ? AND t0.id %s ?))', $sortRef, $operator, $sortRef, $operator),
            [$sortValue, $sortValue, $this->cursor->id],
        ];
    }

    private function orderSql(): string
    {
        if ($this->sortField === null) {
            return sprintf('ORDER BY t0.id %s', $this->sortDirection);
        }

        return sprintf('ORDER BY %s %s, t0.id %s', $this->columnRef($this->sortField), $this->sortDirection, $this->sortDirection);
    }

    /**
     * @param mixed[] $row positional values in the same t0.*, t1.*, ... order selectList() built
     * @param array<string, string[]> $columnNamesByLevel
     * @return array{0: string, 1: array<string, array<string, mixed>>} id, level => its own raw row
     */
    private function splitRow(array $row, array $columnNamesByLevel): array
    {
        $rowsByLevel = [];
        $offset = 0;
        foreach ($this->chain as $level) {
            $names = $columnNamesByLevel[$level];
            $rowsByLevel[$level] = array_combine($names, array_slice($row, $offset, count($names)));
            $offset += count($names);
        }

        return [(string) $rowsByLevel[$this->chain[0]]['id'], $rowsByLevel];
    }

    /**
     * Level => its own table's column names, in the exact order SELECT t{n}.* returns
     * them. Derived from field metadata (SchemaBuilder::realColumnNames()), not live
     * introspection: DBAL/SQLite's schema manager normalizes introspected column names
     * to lowercase, which would silently break slicing a row back apart for any field
     * whose real name isn't already all-lowercase.
     *
     * @return array<string, string[]>
     */
    private function columnNamesByLevel(): array
    {
        $result = [];
        foreach ($this->chain as $level) {
            $result[$level] = [
                SchemaBuilder::ID_COLUMN,
                ...SchemaBuilder::realColumnNames($this->ownFieldsByLevel[$level]),
                SchemaBuilder::BLOB_COLUMN,
            ];
        }

        return $result;
    }

    /** @param array<string, array<string, mixed>> $rowsByLevel */
    private function sortValueFromRows(array $rowsByLevel): mixed
    {
        [$index, $column, $field] = $this->locateField($this->sortField);
        $raw = $rowsByLevel[$this->chain[$index]][$column];

        return $field->kind === FieldKind::Bool ? (bool) $raw : $raw;
    }

    private function columnRef(string $fieldName): string
    {
        [$index, $column] = $this->locateField($fieldName);

        return sprintf('t%d.%s', $index, $column);
    }

    /**
     * Which chain level declares $fieldName, its real column name, and its
     * FieldDescriptor. Throws for anything with no real column to reference in SQL: a
     * blob-only field, an EmbeddedValueObject (queryable is always false for both,
     * enforced by FieldDescriptor's own factories), or a Collection.
     *
     * @return array{0: int, 1: string, 2: FieldDescriptor}
     */
    private function locateField(string $fieldName): array
    {
        foreach ($this->chain as $index => $level) {
            foreach ($this->ownFieldsByLevel[$level] as $field) {
                if ($field->name !== $fieldName) {
                    continue;
                }

                $column = match (true) {
                    $field->kind === FieldKind::EntityReference => $fieldName . '_id',
                    $field->queryable => $fieldName,
                    default => throw new LogicException(sprintf('"%s" has no real column to filter/sort by, it is not queryable.', $fieldName)),
                };

                return [$index, $column, $field];
            }
        }

        throw new LogicException(sprintf('"%s" is not a field of %s or any level of its own chain.', $fieldName, $this->identifier));
    }
}

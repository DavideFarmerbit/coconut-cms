<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Throwable;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelChains;
use XyloIsCoding\CoconutCms\Persistence\Platform\DatabasePlatform;
use XyloIsCoding\CoconutCms\Persistence\Platform\Platforms;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValue;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;

/**
 * Runs a Query as SQL: the queried type's level tables joined on the
 * entity's id, each condition on a real column, sorted by real columns.
 * Content is dense, so every column holds the value a load shows. Joining
 * the queried type's own level table keeps exactly its entities and its
 * subtypes'; only standalone ones count.
 *
 * Pages are keyset pages: a cursor holds the last entity's sort values and
 * id, and the next page starts strictly after them. A null sorts lowest, on
 * every supported database.
 */
final class QueryRunner
{
    /** Every value type that has an order: ordering conditions and nothing else. */
    private const ORDERED = [ScalarType::String, ScalarType::Text, ScalarType::Int, ScalarType::Float, ScalarType::Decimal, ScalarType::Date, ScalarType::DateTime];

    private readonly LevelChains $chains;
    private readonly DatabasePlatform $platform;

    /**
     * @param Closure(string): ?object $load Loads an entity by id, the way Persistence::load() does.
     * @param Closure(): void $guard Throws while entities can't be read.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaLookup $schemas,
        private readonly IdentityMap $identities,
        private readonly Closure $load,
        private readonly Closure $guard,
    ) {
        $this->chains = new LevelChains($schemas);
        $this->platform = Platforms::of($connection);
    }

    public function page(Query $query): Page {
        if ($query->limit === null) {
            throw new InvalidArgumentException('page() needs a limit(): a query never loads everything by accident.');
        }

        // One more than a page: whether it's there says if another page follows.
        $rows = $this->rows($query, $query->limit + 1);
        $hasMore = count($rows) > $query->limit;
        $rows = array_slice($rows, 0, $query->limit);

        $items = [];
        foreach ($rows as $row) {
            $items[] = ($this->load)($row['id']);
        }

        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        $nextCursor = $hasMore && $last !== null
            ? Cursor::encode(Cursor::ordering($query->orders), array_map(static fn (int $index): mixed => $last['o' . $index], array_keys($query->orders)), $last['id'])
            : null;

        return new Page($items, $nextCursor);
    }

    /** @return list<string> */
    public function ids(Query $query): array {
        return array_column($this->rows($query, $query->limit), 'id');
    }

    public function count(Query $query): int {
        ($this->guard)();
        [$builder] = $this->filtered($query);

        return (int) $builder->select('COUNT(*)')->fetchOne();
    }

    /**
     * Each matching entity's id and sort values, "o0", "o1"…, in order.
     * @return list<array<string, mixed>>
     */
    private function rows(Query $query, ?int $limit): array {
        ($this->guard)();
        [$builder, $joins] = $this->filtered($query);
        $builder->select('e.id');

        $sorts = [];
        foreach ($query->orders as $index => [$path, $order]) {
            $column = $joins->column($path)[0];
            $builder->addSelect(sprintf('%s AS o%d', $column, $index));
            $builder->addOrderBy($column, $order === Order::Ascending ? 'ASC' : 'DESC');
            $sorts[] = [$column, $order];
        }
        $builder->addOrderBy('e.id', 'ASC');

        if ($query->cursor !== null) {
            [$values, $id] = Cursor::decode($query->cursor, Cursor::ordering($query->orders));
            if (count($values) !== count($sorts)) {
                throw new InvalidArgumentException('That cursor holds the wrong number of sort values.');
            }

            $builder->andWhere($this->after($builder, $sorts, $values, $id));
        }

        if ($limit !== null) {
            $builder->setMaxResults($limit);
        }

        return $builder->fetchAllAssociative();
    }

    /**
     * The query's entities, every condition applied: no select, no order;
     * and the joins its paths took, for its sorts to reuse.
     * @return array{QueryBuilder, Joins}
     */
    private function filtered(Query $query): array {
        if (!$this->schemas->has($query->identifier)) {
            throw new InvalidArgumentException(sprintf('There is no type "%s" to query.', $query->identifier));
        }

        $builder = $this->connection->createQueryBuilder()
            ->from($this->quoted(DatabaseSchema::ENTITIES), 'e')
            // A standalone entity is owned by Cms through no field.
            ->where("e.owner_field = ''");
        $joins = new Joins($builder, $this->connection, $this->chains, $this->schemas, $query->identifier);

        foreach ($query->conditions as [$path, $condition]) {
            [$column, $field] = $joins->column($path);
            $builder->andWhere($this->conditionSql($builder, $column, $field, $condition, $path));
        }

        return [$builder, $joins];
    }

    /** $condition on $column as SQL, its values bound to $builder. */
    private function conditionSql(QueryBuilder $builder, string $column, FieldDescriptor $field, Condition $condition, string $path): string {
        $this->assertApplies($field, $condition->operator, $path);
        $values = array_map(fn (mixed $value): mixed => $this->stored($field, $value, $path), $condition->values);
        $bind = fn (mixed $value): string => $builder->createNamedParameter($value, self::typeOf($value));

        return match ($condition->operator) {
            Operator::Equal => sprintf('%s = %s', $column, $bind($values[0])),
            Operator::NotEqual => sprintf('(%1$s <> %2$s OR %1$s IS NULL)', $column, $bind($values[0])),
            Operator::Greater => sprintf('%s > %s', $column, $bind($values[0])),
            Operator::GreaterOrEqual => sprintf('%s >= %s', $column, $bind($values[0])),
            Operator::Less => sprintf('%s < %s', $column, $bind($values[0])),
            Operator::LessOrEqual => sprintf('%s <= %s', $column, $bind($values[0])),
            Operator::In => sprintf('%s IN (%s)', $column, $builder->createNamedParameter(
                $values,
                array_filter($values, 'is_int') === $values ? ArrayParameterType::INTEGER : ArrayParameterType::STRING,
            )),
            Operator::IsNull => sprintf('%s IS NULL', $column),
            Operator::IsNotNull => sprintf('%s IS NOT NULL', $column),
            Operator::Like => sprintf('%s LIKE %s', $column, $bind($values[0])),
        };
    }

    /**
     * Throws when $operator makes no sense on $field: an order on a value
     * with none, LIKE on anything but text.
     */
    private function assertApplies(FieldDescriptor $field, Operator $operator, string $path): void {
        $isScalar = $field->kind() === FieldKind::Scalar;

        if ($operator->isOrdering() && !($isScalar && in_array($field->scalarType(), self::ORDERED, true))) {
            throw new InvalidArgumentException(sprintf('"%s" has no order to compare by.', $path));
        }

        if ($operator === Operator::Like && !($isScalar && in_array($field->scalarType(), [ScalarType::String, ScalarType::Text], true))) {
            throw new InvalidArgumentException(sprintf('"%s" isn\'t text: LIKE doesn\'t apply.', $path));
        }
    }

    /**
     * $value, given in PHP form, in the form $field stores it: converted the
     * way a save converts it, so a query and a save never disagree.
     */
    private function stored(FieldDescriptor $field, mixed $value, string $path): mixed {
        try {
            return match ($field->kind()) {
                FieldKind::Scalar => ScalarValues::toStorage($field->scalarType(), $value),
                FieldKind::ValueObject => $value instanceof StorableValue && $value instanceof ($field->target())
                    ? StorableValues::toStorage($value)
                    : throw new InvalidArgumentException(sprintf('it holds a %s, not %s.', $field->target(), get_debug_type($value))),
                FieldKind::Reference => is_object($value)
                    ? $this->identities->idOf($value) ?? throw new InvalidArgumentException(sprintf('the %s it\'s compared with was never saved.', $value::class))
                    : throw new InvalidArgumentException(sprintf('a Reference is compared with an entity, not %s.', get_debug_type($value))),
                default => throw new InvalidArgumentException('it has no value of its own to compare.'),
            };
        } catch (InvalidArgumentException $invalid) {
            throw new InvalidArgumentException(sprintf('"%s": %s', $path, lcfirst($invalid->getMessage())), 0, $invalid);
        } catch (Throwable $failure) {
            throw new InvalidArgumentException(sprintf('"%s": %s', $path, $failure->getMessage()), 0, $failure);
        }
    }

    /**
     * SQL true for an entity sorted strictly after the one a cursor holds:
     * for each sort column in turn, every one before it equal and this one
     * past; the id last, ascending. A null sorts lowest.
     * @param list<array{string, Order}> $sorts Each column and its order.
     * @param list<mixed> $values The cursor's sort values.
     */
    private function after(QueryBuilder $builder, array $sorts, array $values, string $id): string {
        $keys = [];
        foreach ($sorts as $index => [$column, $order]) {
            $keys[] = [$column, $order, $values[$index]];
        }
        $keys[] = ['e.id', Order::Ascending, $id];

        $alternatives = [];
        foreach ($keys as $index => [$column, $order, $value]) {
            $past = $this->past($builder, $column, $order, $value);
            if ($past === null) {
                continue;
            }

            $parts = [];
            foreach (array_slice($keys, 0, $index) as [$earlierColumn, , $earlierValue]) {
                $parts[] = $this->platform->nullSafeEquals($earlierColumn, $builder->createNamedParameter($earlierValue, self::typeOf($earlierValue)));
            }
            $parts[] = $past;
            $alternatives[] = '(' . implode(' AND ', $parts) . ')';
        }

        return $alternatives === [] ? '1 = 0' : '(' . implode(' OR ', $alternatives) . ')';
    }

    /**
     * SQL true for $column sorting strictly past $value in $order; null when
     * nothing can. A null sorts lowest: ascending, everything not null is past
     * one; descending, nothing is.
     */
    private function past(QueryBuilder $builder, string $column, Order $order, mixed $value): ?string {
        if ($value === null) {
            return $order === Order::Ascending ? sprintf('%s IS NOT NULL', $column) : null;
        }

        $bound = $builder->createNamedParameter($value, self::typeOf($value));

        return $order === Order::Ascending
            ? sprintf('%s > %s', $column, $bound)
            : sprintf('(%1$s < %2$s OR %1$s IS NULL)', $column, $bound);
    }

    /** How to bind $value: PDO would send false as an empty string. */
    private static function typeOf(mixed $value): ParameterType {
        return match (true) {
            $value === null => ParameterType::NULL,
            is_bool($value) => ParameterType::BOOLEAN,
            is_int($value) => ParameterType::INTEGER,
            default => ParameterType::STRING,
        };
    }

    private function quoted(string $name): string {
        return $this->connection->quoteSingleIdentifier($name);
    }
}

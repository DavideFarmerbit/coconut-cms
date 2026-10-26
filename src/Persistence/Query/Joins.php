<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\CollectionLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelChains;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\OwnedLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ValueColumn;
use XyloIsCoding\CoconutCms\Persistence\Permission\Permissions;
use XyloIsCoding\CoconutCms\Persistence\Permission\TypeAction;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;

/**
 * The tables one query, or one of its subqueries, reads, and where each
 * path's value is. It starts at a type's entities or at a Collection's items;
 * a path stepping through a relationship, "maker.name" or
 * "engine.horsepower", joins the related entity's levels the first time it's
 * named, then reuses them.
 *
 * Every relationship is a LEFT JOIN: an entity whose relationship is empty
 * still counts, and every field past it reads as null. A Reference is joined
 * on its column; an owned object through its entities row, by owner and
 * owner field. Past a step, only the declared target's fields and its
 * ancestors' are known, the same as at the start.
 *
 * A field the actor may not read is refused the same way as one that doesn't
 * exist, at every step of a path: a condition or a sort on it would show its
 * values through which entities match, or in what order. So is a step past a
 * Reference to a type it may not read; a target of a subtype it may not read
 * joins as missing.
 */
final class Joins
{
    /**
     * Each place reached, by the path that reaches it: "" for the start. A
     * place is a type's entity, its id and its levels' aliases; or a
     * Collection's items, their columns each with its alias.
     * @var array<string, array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>}>
     */
    private array $places = [];

    private int $joined = 0;

    /**
     * @param QueryBuilder $builder Where joins go.
     * @param QueryBuilder $parameters Where parameters are bound: the query
     *        that runs, a subquery's parent included.
     * @param string $from The alias every join hangs off.
     * @param string $prefix Starts every alias made here, unique per subquery.
     * @param array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>, itemType?: ?string} $start
     */
    private function __construct(
        private readonly QueryBuilder $builder,
        private readonly QueryBuilder $parameters,
        private readonly Connection $connection,
        private readonly LevelChains $chains,
        private readonly SchemaLookup $schemas,
        private readonly Permissions $permissions,
        private readonly string $from,
        private readonly string $prefix,
        array $start,
    ) {
        $this->places[''] = $start;
    }

    /**
     * Starting at the $identifier entities $from holds the entities rows of:
     * their levels inner joined, since every one has them.
     */
    public static function forEntities(QueryBuilder $builder, QueryBuilder $parameters, Connection $connection, LevelChains $chains, SchemaLookup $schemas, Permissions $permissions, string $identifier, string $from, string $prefix): self {
        $joins = new self($builder, $parameters, $connection, $chains, $schemas, $permissions, $from, $prefix, ['type' => $identifier, 'id' => $from . '.id', 'levels' => []]);

        $levels = [];
        foreach ($chains->of($identifier) as $index => $level) {
            $alias = $prefix . 'l' . $index;
            $builder->innerJoin($from, $joins->quoted($level->table), $alias, sprintf('%s.%s = %s.id', $alias, $joins->quoted(ColumnNames::ID), $from));
            $levels[] = $alias;
        }
        $joins->places['']['levels'] = $levels;

        return $joins;
    }

    /**
     * Starting at the items of $collection, whose table $from holds: an Embed
     * item's fields are its columns; a Reference item's paths are its
     * target's, joined on the item's value.
     */
    public static function forItems(QueryBuilder $builder, QueryBuilder $parameters, Connection $connection, LevelChains $chains, SchemaLookup $schemas, Permissions $permissions, CollectionLayout $collection, FieldDescriptor $item, string $from, string $prefix): self {
        $columns = array_map(static fn (ValueColumn $column): array => [$from, $column], $collection->itemColumns);
        $itemType = $item->kind() === FieldKind::Embed ? $item->target() : null;
        $joins = new self($builder, $parameters, $connection, $chains, $schemas, $permissions, $from, $prefix, ['type' => null, 'id' => null, 'levels' => [], 'columns' => $columns, 'itemType' => $itemType]);

        if ($item->kind() === FieldKind::Reference) {
            $joins->places[''] = $joins->joinReferenced(sprintf('%s.%s', $from, $joins->quoted(ColumnNames::VALUE)), $item->target());
        }

        return $joins;
    }

    /**
     * The column holding $path's value, qualified by its table's alias, and
     * the field holding it. Joins whatever $path steps through. Throws for a
     * path leading to no value: a typo, a subtype's field, a Collection, an
     * owned object itself.
     * @return array{string, FieldDescriptor}
     */
    public function column(string $path): array {
        [$place, $step, $at] = $this->walk($path);
        $this->assertReadable($place, $step, $path);

        foreach ($this->columnsOf($place) as [$alias, $column]) {
            if ((string) $column->path === $step) {
                return [sprintf('%s.%s', $alias, $this->quoted($column->name)), $column->field];
            }
        }

        foreach ($this->ownedOf($place) as [, $owned]) {
            if ((string) $owned->path === $step) {
                throw self::unknown($path, $owned->countColumn !== null
                    ? sprintf('"%s" is an Owned Collection: whereHas() and whereCount() ask about it', $at)
                    : sprintf('"%s" is an owned object: name one of its fields, "%s.field"', $at, $at));
            }
        }

        foreach ($this->collectionsOf($place) as $collection) {
            if ((string) $collection->path === $step) {
                throw self::unknown($path, sprintf('"%s" is a Collection: whereHas() and whereCount() ask about it', $at));
            }
        }

        throw self::unknown($path, self::noField($place, $step));
    }

    /**
     * The Collection at $path: where its items are, whose they are, the item
     * descriptor, and where its length is stored when it's an Owned
     * Collection.
     * @return array{owned: ?OwnedLayout, values: ?CollectionLayout, owner: string, item: FieldDescriptor, count: ?string}
     */
    public function collection(string $path): array {
        [$place, $step, $at] = $this->walk($path);
        $this->assertReadable($place, $step, $path);

        foreach ($this->ownedOf($place) as [$alias, $owned]) {
            if ((string) $owned->path === $step) {
                if ($owned->countColumn === null) {
                    throw self::unknown($path, sprintf('"%s" is a single owned object, not a Collection', $at));
                }

                return ['owned' => $owned, 'values' => null, 'owner' => $place['id'], 'item' => $this->fieldOf($place['type'], $step)->itemDescriptor(), 'count' => sprintf('%s.%s', $alias, $this->quoted($owned->countColumn))];
            }
        }

        foreach ($this->collectionsOf($place) as $collection) {
            if ((string) $collection->path === $step) {
                return ['owned' => null, 'values' => $collection, 'owner' => $place['id'], 'item' => $this->fieldOf($place['type'], $step)->itemDescriptor(), 'count' => null];
            }
        }

        throw self::unknown($path, $this->hasColumn($place, $step) ? sprintf('"%s" holds a value, not a Collection', $at) : self::noField($place, $step));
    }

    /**
     * The column holding an item's own value, and its descriptor: for a
     * Scalar, ValueObject or Reference item, tested with Item::is().
     * @return array{string, FieldDescriptor}
     */
    public function itemValue(FieldDescriptor $item): array {
        return [sprintf('%s.%s', $this->from, $this->quoted(ColumnNames::VALUE)), $item];
    }

    /**
     * The place $path's last step is in, every step before it joined; that
     * step; and the path up to it, for messages.
     * @return array{array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>}, string, string}
     */
    private function walk(string $path): array {
        $steps = explode('.', $path);
        $place = $this->places[''];
        $reached = '';

        foreach (array_slice($steps, 0, -1) as $step) {
            $at = $reached === '' ? $step : $reached . '.' . $step;
            $place = $this->places[$at] ??= $this->step($place, $step, $path, $at);
            $reached = $at;
        }

        $last = $steps[array_key_last($steps)];

        return [$place, $last, $reached === '' ? $last : $reached . '.' . $last];
    }

    /**
     * The place a step through $step from $place reaches, joined.
     * @param array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>} $place
     * @return array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>}
     */
    private function step(array $place, string $step, string $path, string $at): array {
        $this->assertReadable($place, $step, $path);

        foreach ($this->columnsOf($place) as [$alias, $column]) {
            if ((string) $column->path === $step) {
                if ($column->field->kind() !== FieldKind::Reference) {
                    throw self::unknown($path, sprintf('"%s" holds a value, not a relationship to step through', $at));
                }

                return $this->joinReferenced(sprintf('%s.%s', $alias, $this->quoted($column->name)), $column->field->target());
            }
        }

        foreach ($this->ownedOf($place) as [, $owned]) {
            if ((string) $owned->path === $step) {
                if ($owned->countColumn !== null) {
                    throw self::unknown($path, sprintf('"%s" is an Owned Collection: whereHas() asks about its items', $at));
                }

                return $this->joinOwned($place['id'], $owned->ownerField(), $this->fieldOf($place['type'], $step)->target());
            }
        }

        foreach ($this->collectionsOf($place) as $collection) {
            if ((string) $collection->path === $step) {
                throw self::unknown($path, sprintf('"%s" is a Collection: whereHas() asks about its items', $at));
            }
        }

        throw self::unknown($path, self::noField($place, $step));
    }

    /**
     * Throws, as for a field that doesn't exist, unless the actor may read
     * every field $step names in $place, through any Embeds:
     * "chassis:weight" is both chassis and its weight. Throws for any step
     * in a place of a type it may not read.
     * @param array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>, itemType?: ?string, denied?: true} $place
     */
    private function assertReadable(array $place, string $step, string $path): void {
        if ($place['denied'] ?? false) {
            throw self::unknown($path, sprintf('its actor may not read a %s', $place['type']));
        }

        $type = $place['type'] ?? $place['itemType'] ?? null;
        foreach (explode(':', $step) as $segment) {
            if ($type === null || !$this->schemas->hierarchyFieldsOf($type)->has(FieldName::of($segment))) {
                return;
            }

            if (!$this->permissions->canRead($type, $segment)) {
                throw self::unknown($path, self::noField($place, $step));
            }

            $field = $this->schemas->hierarchyFieldsOf($type)->get(FieldName::of($segment));
            $type = $field->kind() === FieldKind::Embed ? $field->target() : null;
        }
    }

    /**
     * Every value column of $place, each with its table's alias.
     * @param array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>} $place
     * @return list<array{string, ValueColumn}>
     */
    private function columnsOf(array $place): array {
        if (isset($place['columns'])) {
            return $place['columns'];
        }

        $columns = [];
        foreach ($this->chains->of($place['type']) as $index => $level) {
            foreach ($level->columns as $column) {
                $columns[] = [$place['levels'][$index], $column];
            }
        }

        return $columns;
    }

    /**
     * Every owned field of $place, each with its level's alias: an item has none.
     * @param array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>} $place
     * @return list<array{string, OwnedLayout}>
     */
    private function ownedOf(array $place): array {
        if (isset($place['columns'])) {
            return [];
        }

        $owned = [];
        foreach ($this->chains->of($place['type']) as $index => $level) {
            foreach ($level->owned as $each) {
                $owned[] = [$place['levels'][$index], $each];
            }
        }

        return $owned;
    }

    /**
     * Every Collection of $place that isn't owned: an item has none.
     * @param array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>} $place
     * @return list<CollectionLayout>
     */
    private function collectionsOf(array $place): array {
        if (isset($place['columns'])) {
            return [];
        }

        $collections = [];
        foreach ($this->chains->of($place['type']) as $level) {
            array_push($collections, ...$level->collections);
        }

        return $collections;
    }

    /** @param array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>} $place */
    private function hasColumn(array $place, string $step): bool {
        foreach ($this->columnsOf($place) as [, $column]) {
            if ((string) $column->path === $step) {
                return true;
            }
        }

        return false;
    }

    /**
     * Joins the levels of the $type entity a Reference column points at. A
     * $type the actor may not read joins nothing: any path past it is
     * refused. A target of a subtype it may not read joins as missing, so
     * every field past it reads null.
     * @return array{type: string, id: string, levels: list<string>, denied?: true}
     */
    private function joinReferenced(string $referenceColumn, string $type): array {
        if (!$this->permissions->may($type, TypeAction::Read)) {
            return ['type' => $type, 'id' => $referenceColumn, 'levels' => [], 'denied' => true];
        }

        $unreadable = $this->permissions->unreadableUnder($type);
        if ($unreadable === []) {
            return ['type' => $type, 'id' => $referenceColumn, 'levels' => $this->joinLevels($type, $referenceColumn)];
        }

        $alias = $this->prefix . 'r' . $this->joined++;
        $this->builder->leftJoin($this->from, $this->quoted(DatabaseSchema::ENTITIES), $alias, sprintf(
            '%1$s.id = %2$s AND %1$s.concrete_identifier NOT IN (%3$s)',
            $alias,
            $referenceColumn,
            $this->parameters->createNamedParameter($unreadable, ArrayParameterType::STRING),
        ));

        return ['type' => $type, 'id' => $alias . '.id', 'levels' => $this->joinLevels($type, $alias . '.id')];
    }

    /**
     * Joins the $type object an entity owns at $ownerField: its entities row,
     * then its levels.
     * @return array{type: string, id: string, levels: list<string>}
     */
    private function joinOwned(string $ownerId, string $ownerField, string $type): array {
        $alias = $this->prefix . 'o' . $this->joined++;
        $this->builder->leftJoin($this->from, $this->quoted(DatabaseSchema::ENTITIES), $alias, sprintf(
            '%1$s.owner = %2$s AND %1$s.owner_field = %3$s AND %1$s.position = -1',
            $alias,
            $ownerId,
            $this->parameters->createNamedParameter($ownerField),
        ));

        return ['type' => $type, 'id' => $alias . '.id', 'levels' => $this->joinLevels($type, $alias . '.id')];
    }

    /**
     * LEFT JOINs each of $type's levels on the entity whose id $id holds.
     * @return list<string> Their aliases, root first.
     */
    private function joinLevels(string $type, string $id): array {
        $aliases = [];
        foreach ($this->chains->of($type) as $level) {
            $alias = $this->prefix . 'j' . $this->joined++;
            $this->builder->leftJoin($this->from, $this->quoted($level->table), $alias, sprintf('%s.%s = %s', $alias, $this->quoted(ColumnNames::ID), $id));
            $aliases[] = $alias;
        }

        return $aliases;
    }

    /** The field at $path of $type, through any Embeds: "chassis:spare". */
    private function fieldOf(string $type, string $path): FieldDescriptor {
        $fields = $this->schemas->hierarchyFieldsOf($type);
        $segments = explode(':', $path);
        foreach ($segments as $index => $segment) {
            $field = $fields->get(FieldName::of($segment));
            if ($index === count($segments) - 1) {
                return $field;
            }

            $fields = $this->schemas->hierarchyFieldsOf($field->target());
        }

        throw new InvalidArgumentException(sprintf('"%s" names no field.', $path));
    }

    /** @param array{type: ?string, id: ?string, levels: list<string>, columns?: list<array{string, ValueColumn}>} $place */
    private static function noField(array $place, string $step): string {
        return $place['type'] === null
            ? sprintf('an item has no field "%s"', $step)
            : sprintf('"%s" has no field "%s": only its own fields and its ancestors\', an Embed\'s written "embed:field"', $place['type'], $step);
    }

    private static function unknown(string $path, string $why): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('A query can\'t use "%s": %s.', $path, $why));
    }

    private function quoted(string $name): string {
        return $this->connection->quoteSingleIdentifier($name);
    }
}

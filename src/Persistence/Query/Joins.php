<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\ColumnNames;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelChains;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;

/**
 * The tables one query reads, and where each path's value is. The queried
 * type's levels are joined from the start; a path stepping through a
 * relationship, "maker.name" or "engine.horsepower", joins the related
 * entity's levels the first time it's named, then reuses them.
 *
 * Every relationship is a LEFT JOIN: an entity whose relationship is empty
 * still counts, and every field past it reads as null. A Reference is joined
 * on its column; an owned object through its entities row, by owner and
 * owner field. Past a step, only the declared target's fields and its
 * ancestors' are known, the same as at the root.
 */
final class Joins
{
    /**
     * Each place reached, by the path that reaches it: "" for the queried
     * type itself.
     * @var array<string, array{type: string, id: string, levels: list<string>}>
     */
    private array $places = [];

    private int $joined = 0;

    public function __construct(
        private readonly QueryBuilder $builder,
        private readonly Connection $connection,
        private readonly LevelChains $chains,
        private readonly SchemaLookup $schemas,
        string $identifier,
    ) {
        $levels = [];
        foreach ($this->chains->of($identifier) as $index => $level) {
            $alias = 'l' . $index;
            $this->builder->innerJoin('e', $this->quoted($level->table), $alias, sprintf('%s.%s = e.id', $alias, $this->quoted(ColumnNames::ID)));
            $levels[] = $alias;
        }

        $this->places[''] = ['type' => $identifier, 'id' => 'e.id', 'levels' => $levels];
    }

    /**
     * The column holding $path's value, qualified by its table's alias, and
     * the field holding it. Joins whatever $path steps through. Throws for a
     * path leading to no value: a typo, a subtype's field, a Collection, an
     * owned object itself.
     * @return array{string, FieldDescriptor}
     */
    public function column(string $path): array {
        $steps = explode('.', $path);
        $place = $this->places[''];
        $reached = '';

        foreach ($steps as $index => $step) {
            $isLast = $index === count($steps) - 1;
            $at = $reached === '' ? $step : $reached . '.' . $step;

            foreach ($this->chains->of($place['type']) as $levelIndex => $level) {
                foreach ($level->columns as $column) {
                    if ((string) $column->path !== $step) {
                        continue;
                    }

                    $qualified = sprintf('%s.%s', $place['levels'][$levelIndex], $this->quoted($column->name));
                    if ($isLast) {
                        return [$qualified, $column->field];
                    }

                    if ($column->field->kind() !== FieldKind::Reference) {
                        throw self::unknown($path, sprintf('"%s" holds a value, not a relationship to step through', $at));
                    }

                    $place = $this->places[$at] ??= $this->joinReferenced($qualified, $column->field->target());
                    $reached = $at;
                    continue 3;
                }

                foreach ($level->owned as $owned) {
                    if ((string) $owned->path !== $step) {
                        continue;
                    }

                    if ($owned->countColumn !== null) {
                        throw self::unknown($path, sprintf('"%s" is an Owned Collection: conditions on a Collection come with whereHas() (not built yet)', $at));
                    }

                    if ($isLast) {
                        throw self::unknown($path, sprintf('"%s" is an owned object: name one of its fields, "%s.field"', $at, $at));
                    }

                    $place = $this->places[$at] ??= $this->joinOwned($place['id'], $owned->ownerField(), $this->targetOf($place['type'], $step));
                    $reached = $at;
                    continue 3;
                }

                foreach ($level->collections as $collection) {
                    if ((string) $collection->path === $step) {
                        throw self::unknown($path, sprintf('"%s" is a Collection: conditions on a Collection come with whereHas() (not built yet)', $at));
                    }
                }
            }

            throw self::unknown($path, sprintf('"%s" has no field "%s": only its own fields and its ancestors\', an Embed\'s written "embed:field"', $place['type'], $step));
        }

        throw self::unknown($path, 'it names nothing');
    }

    /**
     * Joins the levels of the $type entity a Reference column points at.
     * @return array{type: string, id: string, levels: list<string>}
     */
    private function joinReferenced(string $referenceColumn, string $type): array {
        return ['type' => $type, 'id' => $referenceColumn, 'levels' => $this->joinLevels($type, $referenceColumn)];
    }

    /**
     * Joins the $type object an entity owns at $ownerField: its entities row,
     * then its levels.
     * @return array{type: string, id: string, levels: list<string>}
     */
    private function joinOwned(string $ownerId, string $ownerField, string $type): array {
        $alias = 'o' . $this->joined++;
        $this->builder->leftJoin('e', $this->quoted(DatabaseSchema::ENTITIES), $alias, sprintf(
            '%1$s.owner = %2$s AND %1$s.owner_field = %3$s AND %1$s.position = -1',
            $alias,
            $ownerId,
            $this->builder->createNamedParameter($ownerField),
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
            $alias = 'j' . $this->joined++;
            $this->builder->leftJoin('e', $this->quoted($level->table), $alias, sprintf('%s.%s = %s', $alias, $this->quoted(ColumnNames::ID), $id));
            $aliases[] = $alias;
        }

        return $aliases;
    }

    /** The type the OwningReference at $path of $type holds, through any Embeds: "chassis:spare". */
    private function targetOf(string $type, string $path): string {
        $segments = explode(':', $path);
        $fields = $this->schemas->hierarchyFieldsOf($type);
        foreach ($segments as $index => $segment) {
            $field = $fields->get(FieldName::of($segment));
            if ($index === count($segments) - 1) {
                return $field->target();
            }

            $fields = $this->schemas->hierarchyFieldsOf($field->target());
        }

        throw new InvalidArgumentException(sprintf('"%s" names no field.', $path));
    }

    private static function unknown(string $path, string $why): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('A query can\'t use "%s": %s.', $path, $why));
    }

    private function quoted(string $name): string {
        return $this->connection->quoteSingleIdentifier($name);
    }
}

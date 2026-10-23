<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Every place an aggregate holds an object, found through the schemas as they
 * are at one point of the migration: the root, every owned object, every
 * Embed, at any depth.
 */
final class Occurrences
{
    /**
     * Deepest first, so a fixup changing every occurrence of a type that
     * nests inside itself never moves one it hasn't reached yet.
     * @return list<Occurrence>
     */
    public static function in(AggregateValues $values, SchemaState $schemas): array {
        $found = [];
        self::walk(new Occurrence(null, $values->identifier, OccurrenceKind::Root, FieldPath::TRAVERSE), $values, $schemas, $found);
        usort($found, static fn (Occurrence $a, Occurrence $b): int => $b->depth() <=> $a->depth());

        return $found;
    }

    /**
     * Every occurrence of $type or a subclass of it, deepest first.
     * @return list<Occurrence>
     */
    public static function ofType(string $type, AggregateValues $values, SchemaState $schemas): array {
        return array_values(array_filter(
            self::in($values, $schemas),
            static fn (Occurrence $occurrence): bool => $schemas->isA($occurrence->identifier, $type),
        ));
    }

    /** @param list<Occurrence> $found */
    private static function walk(Occurrence $occurrence, AggregateValues $values, SchemaState $schemas, array &$found): void {
        $found[] = $occurrence;

        foreach ($schemas->hierarchyFieldsOf($occurrence->identifier) as $name => $field) {
            $path = $occurrence->fieldPath($name);

            match ($field->kind()) {
                FieldKind::Embed => self::walk(new Occurrence($path, $field->target(), OccurrenceKind::Embedded, FieldPath::EMBED), $values, $schemas, $found),
                FieldKind::OwningReference => self::walkOwned($path, $values, $schemas, $found),
                FieldKind::Collection => self::walkItems($path, $field->itemDescriptor(), $values, $schemas, $found),
                default => null,
            };
        }
    }

    /** @param list<Occurrence> $found */
    private static function walkOwned(FieldPath $path, AggregateValues $values, SchemaState $schemas, array &$found): void {
        $identifier = $values->get($path);
        if ($identifier !== null) {
            self::walk(new Occurrence($path, $identifier, OccurrenceKind::Owned, FieldPath::TRAVERSE), $values, $schemas, $found);
        }
    }

    /** @param list<Occurrence> $found */
    private static function walkItems(FieldPath $path, FieldDescriptor $item, AggregateValues $values, SchemaState $schemas, array &$found): void {
        $count = (int) $values->get($path);
        for ($index = 0; $index < $count; $index++) {
            $itemPath = $path->traverse($index);

            match ($item->kind()) {
                FieldKind::Embed => self::walk(new Occurrence($itemPath, $item->target(), OccurrenceKind::Embedded, FieldPath::TRAVERSE), $values, $schemas, $found),
                FieldKind::OwningReference => self::walkOwned($itemPath, $values, $schemas, $found),
                default => null,
            };
        }
    }
}

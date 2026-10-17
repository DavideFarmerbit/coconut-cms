<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use InvalidArgumentException;

/**
 * Collects every produced EntitySchema and orders them so a parent or Embed target
 * always comes before whatever depends on it. Reference/OwningReference targets are
 * never dependencies here: they carry no column or flattened field of their own, so
 * nothing about resolving them requires the target's own schema to exist first.
 */
final class SchemaRegistry
{
    /**
     * @param array<string, EntitySchema> $schemas Keyed by identifier.
     * @return array<string, EntitySchema> The same schemas, dependency-ordered.
     */
    public static function sort(array $schemas): array {
        $state = [];
        $order = [];
        $path = [];

        foreach (array_keys($schemas) as $identifier) {
            if (!isset($state[$identifier])) {
                self::visit($identifier, $schemas, $state, $order, $path);
            }
        }

        return $order;
    }

    /**
     * Visits all the dependencies of a schema, recursively, to add all of them to $dependencyOrder before adding itself.
     * @param array<string, EntitySchema> $schemas
     * @param array<string, string> $state Keyed by identifier: "visiting" or "visited".
     * @param array<string, EntitySchema> $dependencyOrder
     * @param string[] $path The identifiers currently on the call stack, in order.
     * @throws InvalidArgumentException if a dependency was never registered, or if a cycle is detected.
     */
    private static function visit(string $identifier, array $schemas, array &$state, array &$dependencyOrder, array &$path): void {
        if (!isset($schemas[$identifier])) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is referenced as a parent or Embed target but was never given to the registry.',
                $identifier,
            ));
        }

        // Track if this schema is being visited so we can detect cycles:
        // if we find a dependency marked as 'visiting' we are in a cycle.
        $state[$identifier] = 'visiting';
        // Add identifier to $path so a dependency cycle can name its own full chain,
        // not just the one identifier that closed it.
        $path[] = $identifier;

        // Add to $dependencyOrder all the dependencies of this schema (recursively)
        foreach (self::dependenciesOf($schemas[$identifier]) as $dependency) {
            if (($state[$dependency] ?? null) === 'visiting') {
                // $dependency is still on the call stack, so everything from its first occurrence in
                // $path up to here, plus $dependency itself, is the cycle.
                $cycle = array_slice($path, (int) array_search($dependency, $path, true));
                $cycle[] = $dependency;

                throw new InvalidArgumentException(sprintf('Cycle detected: %s.', implode(' -> ', $cycle)));
            }

            // A dependency already fully processed needs no second visit.
            if (($state[$dependency] ?? null) !== 'visited') {
                self::visit($dependency, $schemas, $state, $dependencyOrder, $path);
            }
        }
        
        array_pop($path);
        $state[$identifier] = 'visited';
        // Every dependency of this schema is already in $dependencyOrder by this point, so appending here
        // keeps the ordering guarantee intact.
        $dependencyOrder[$identifier] = $schemas[$identifier];
    }

    /**
     * Returns this schema's own direct dependencies: its parent, if any, plus every
     * Embed target among its own fields.
     * @return string[] identifier of the dependencies schemas
     */
    private static function dependenciesOf(EntitySchema $schema): array {
        $dependencies = $schema->parent !== null ? [$schema->parent] : [];

        foreach ($schema->fields as $field) {
            $target = self::embedTargetOf($field);
            if ($target !== null) {
                $dependencies[] = $target;
            }
        }

        return $dependencies;
    }

    /** 
     * Returns this field's own Embed target, found through a Collection's item if needed, or null. 
     * @return string|null identifier of the target schema 
     */
    private static function embedTargetOf(FieldDescriptor $field): ?string {
        return match ($field->kind()) {
            FieldKind::Embed => $field->target(),
            FieldKind::Collection => self::embedTargetOf($field->itemDescriptor()),
            default => null,
        };
    }

    /**
     * Every schema's own fields plus everything it inherits through its own
     * parent chain, resolved transitively all the way up.
     * @param array<string, EntitySchema> $sortedSchemas As returned by sort()
     * @return array<string, FieldDescriptor[]> Keyed by schema identifier
     */
    public static function hierarchyFields(array $sortedSchemas): array {
        $hierarchyFields = [];

        foreach ($sortedSchemas as $identifier => $schema) {
            $inherited = $schema->parent !== null ? $hierarchyFields[$schema->parent] : [];
            $hierarchyFields[$identifier] = [...$inherited, ...$schema->fields];
        }

        return $hierarchyFields;
    }
}

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
     * @param array<string, EntitySchema> $schemas
     * @param array<string, string> $state Keyed by identifier: "visiting" or "visited".
     * @param array<string, EntitySchema> $order
     * @param string[] $path The identifiers currently on the call stack, in order.
     */
    private static function visit(string $identifier, array $schemas, array &$state, array &$order, array &$path): void {
        if (!isset($schemas[$identifier])) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is referenced as a parent or Embed target but was never given to the registry.',
                $identifier,
            ));
        }

        $state[$identifier] = 'visiting';
        $path[] = $identifier;

        foreach (self::dependenciesOf($schemas[$identifier]) as $dependency) {
            if (($state[$dependency] ?? null) === 'visiting') {
                $cycle = array_slice($path, (int) array_search($dependency, $path, true));
                $cycle[] = $dependency;

                throw new InvalidArgumentException(sprintf('Cycle detected: %s.', implode(' -> ', $cycle)));
            }

            if (($state[$dependency] ?? null) !== 'visited') {
                self::visit($dependency, $schemas, $state, $order, $path);
            }
        }

        array_pop($path);
        $state[$identifier] = 'visited';
        $order[$identifier] = $schemas[$identifier];
    }

    /** @return string[] */
    private static function dependenciesOf(EntitySchema $schema): array {
        $dependencies = $schema->parent !== null ? [$schema->parent] : [];

        foreach ($schema->fields as $field) {
            $dependencies = [...$dependencies, ...self::embedTargetsOf($field)];
        }

        return $dependencies;
    }

    /** @return string[] */
    private static function embedTargetsOf(FieldDescriptor $field): array {
        return match ($field->kind()) {
            FieldKind::Embed => [$field->target()],
            FieldKind::Collection => self::embedTargetsOf($field->itemDescriptor()),
            default => [],
        };
    }
}

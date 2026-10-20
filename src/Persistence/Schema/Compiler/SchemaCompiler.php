<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use Closure;
use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaRegistry;

/**
 * Turns a batch of already-read blueprints into their final EntitySchemas:
 * orders the batch so a parent or Embed target always comes before whatever
 * depends on it, registers every result with the request's SchemaRegistry, and
 * writes each one's own default values to SchemaFieldValueStore. A dependency
 * outside the given batch is expected to already be registered with the
 * registry, from an earlier compile() call.
 */
final class SchemaCompiler
{
    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly SchemaFieldValueStore $valueStore,
    ) {
    }

    /**
     * @param array<string, EntityBlueprint> $blueprints Keyed by identifier.
     * @return array<string, EntitySchema> Keyed by identifier, one entry per
     *         $blueprints, in no particular order.
     */
    public function compile(array $blueprints): array {
        $order = $this->sort($blueprints);

        // Compute: every schema and every value, every check along the way.
        // Nothing is registered or written yet, so a failure here leaves the
        // registry and the store exactly as they were.
        $schemas = [];
        foreach ($order as $identifier => $blueprint) {
            $schemas[$identifier] = new EntitySchema(
                identifier: $blueprint->identifier,
                parent: $blueprint->parent,
                table: $blueprint->table,
                editorExtensible: $blueprint->editorExtensible,
                fields: $blueprint->fields,
                fieldPermissions: $blueprint->fieldPermissions,
                fieldValidators: $blueprint->fieldValidators,
                uniqueGroups: $blueprint->uniqueGroups,
                prototypeValidators: $blueprint->prototypeValidators,
            );
        }

        $valuesByIdentifier = [];
        foreach ($order as $identifier => $blueprint) {
            $valuesByPath = [];
            $this->flattenValues($blueprint->fields, $blueprint->defaultValues, FieldPath::of(...), $schemas, $valuesByPath);
            $valuesByIdentifier[$identifier] = $valuesByPath;
        }

        // Commit. The store goes first: it's the only step that can still
        // fail, and registering can't, so a failed write registers nothing.
        $this->valueStore->saveAll($valuesByIdentifier);
        foreach ($schemas as $schema) {
            $this->registry->register($schema);
        }

        return $schemas;
    }

    /*================================================================================================================*/
    // Value Flattening

    /**
     * Writes $values into $valuesByPath, one entry per leaf: an Embed's fields
     * joined with ":", an OwningReference or a Collection item traversed with ".".
     * @param array<string, mixed> $values Keyed by FieldName.
     * @param Closure(FieldName): FieldPath $pathOf Turns one of $fields' names into its full path.
     * @param array<string, EntitySchema> $schemas This batch's own, not yet registered.
     * @param array<string, mixed> $valuesByPath Keyed by FieldPath.
     */
    private function flattenValues(FieldDescriptorCollection $fields, array $values, Closure $pathOf, array $schemas, array &$valuesByPath): void {
        foreach ($fields as $name => $field) {
            $this->flattenValue($field, $values[(string) $name] ?? null, $pathOf($name), $schemas, $valuesByPath);
        }
    }

    /**
     * Dispatch correct 'flatten' method based on field kind.
     * @param array<string, EntitySchema> $schemas This batch's own, not yet registered.
     * @param array<string, mixed> $valuesByPath Keyed by FieldPath.
     */
    private function flattenValue(FieldDescriptor $field, mixed $value, FieldPath $path, array $schemas, array &$valuesByPath): void {
        match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => $valuesByPath[(string) $path] = $value,
            FieldKind::Embed => $this->flattenValues($this->hierarchyFieldsOf($field->target(), $schemas), $value ?? [], $path->embed(...), $schemas, $valuesByPath),
            FieldKind::OwningReference => $this->flattenOwned($field, $value, $path, $schemas, $valuesByPath),
            FieldKind::Collection => $this->flattenCollection($field->itemDescriptor(), $value ?? [], $path, $schemas, $valuesByPath),
        };
    }

    /**
     * The bare path holds the concrete class, or null: an OwningReference has
     * no value of its own there otherwise, and unlike Embed its class isn't
     * fixed by the schema.
     * @param array{class: class-string, fields: array<string, mixed>}|null $value
     * @param array<string, EntitySchema> $schemas This batch's own, not yet registered.
     * @param array<string, mixed> $valuesByPath Keyed by FieldPath.
     */
    private function flattenOwned(FieldDescriptor $field, ?array $value, FieldPath $path, array $schemas, array &$valuesByPath): void {
        $valuesByPath[(string) $path] = $value['class'] ?? null;

        if ($value !== null) {
            $this->assertIsOrExtends($value['class'], $field->target(), $path, $schemas);
            $this->flattenValues($this->hierarchyFieldsOf($value['class'], $schemas), $value['fields'], $path->traverse(...), $schemas, $valuesByPath);
        }
    }

    /**
     * Throws unless $class exists and is $target or one of its descendants.
     * Walks parents through the schemas rather than using is_a(), since an
     * editor-created identifier isn't a PHP class.
     * @param array<string, EntitySchema> $schemas This batch's own, not yet registered.
     */
    private function assertIsOrExtends(string $class, string $target, FieldPath $path, array $schemas): void {
        if (!isset($schemas[$class]) && !$this->registry->has($class)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" holds a "%s", which was neither given to compile() nor already compiled this request.',
                $path,
                $class,
            ));
        }

        for ($current = $class; $current !== null; $current = $this->schemaOf($current, $schemas)->parent) {
            if ($current === $target) {
                return;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '"%s" holds a "%s", which is neither "%s" nor one of its descendants.',
            $path,
            $class,
            $target,
        ));
    }

    /**
     * Each item is its own row in a child table, so it's traversed with "."
     * and its index, never embedded into the owner with ":".
     * @param mixed[] $items
     * @param array<string, EntitySchema> $schemas This batch's own, not yet registered.
     * @param array<string, mixed> $valuesByPath Keyed by FieldPath.
     */
    private function flattenCollection(FieldDescriptor $item, array $items, FieldPath $path, array $schemas, array &$valuesByPath): void {
        foreach (array_values($items) as $index => $value) {
            $itemPath = $path->traverse($index);

            if ($item->kind() === FieldKind::Embed) {
                $this->flattenValues($this->hierarchyFieldsOf($item->target(), $schemas), $value ?? [], $itemPath->traverse(...), $schemas, $valuesByPath);
            } else {
                $this->flattenValue($item, $value, $itemPath, $schemas, $valuesByPath);
            }
        }
    }

    /**
     * $identifier's own fields plus everything it inherits, all the way up.
     * @param array<string, EntitySchema> $schemas This batch's own, not yet registered.
     */
    private function hierarchyFieldsOf(string $identifier, array $schemas): FieldDescriptorCollection {
        $schema = $this->schemaOf($identifier, $schemas);
        $inherited = $schema->parent !== null ? $this->hierarchyFieldsOf($schema->parent, $schemas) : FieldDescriptorCollection::empty();

        return $inherited->merge($schema->fields);
    }

    /**
     * This batch's own schema for $identifier if it has one, the registry's
     * otherwise: the batch always wins, so a schema being recompiled is never
     * read from an older registration.
     * @param array<string, EntitySchema> $schemas This batch's own, not yet registered.
     */
    private function schemaOf(string $identifier, array $schemas): EntitySchema {
        return $schemas[$identifier] ?? $this->registry->get($identifier);
    }

    // ~Value Flattening
    /*================================================================================================================*/
    
    /*================================================================================================================*/
    // Topologial Sorting

    /**
     * @param array<string, EntityBlueprint> $blueprints
     * @return array<string, EntityBlueprint>
     */
    private function sort(array $blueprints): array {
        $state = [];
        $order = [];
        $path = [];

        foreach (array_keys($blueprints) as $identifier) {
            if (!isset($state[$identifier])) {
                $this->visit($identifier, $blueprints, $state, $order, $path);
            }
        }

        return $order;
    }

    /**
     * Adds $identifier to $order only once every dependency it has is already
     * there, so $order stays dependency-ordered. Throws if $identifier isn't in
     * $blueprints and isn't already registered from an earlier compile() call, if
     * one of its dependencies is already being processed higher up the same call
     * stack (a cycle), or if one of its weak dependencies exists nowhere.
     * @param array<string, EntityBlueprint> $blueprints
     * @param array<string, string> $state Keyed by identifier: "visiting" or "visited".
     * @param array<string, EntityBlueprint> $order
     * @param string[] $path The identifiers currently on the call stack, in order.
     */
    private function visit(string $identifier, array $blueprints, array &$state, array &$order, array &$path): void {
        if (!isset($blueprints[$identifier])) {
            if ($this->registry->has($identifier)) {
                // Already compiled in an earlier batch this request: its own
                // dependencies were already satisfied then, nothing more to check.
                return;
            }

            throw new InvalidArgumentException(sprintf(
                '"%s" is referenced as a parent or Embed target but was neither given to compile() nor already compiled this request.',
                $identifier,
            ));
        }

        $state[$identifier] = 'visiting';
        $path[] = $identifier;

        foreach ($this->dependenciesOf($blueprints[$identifier]) as $dependency) {
            if (($state[$dependency] ?? null) === 'visiting') {
                // $dependency is still on the call stack, so everything from its
                // first occurrence in $path up to here, plus $dependency itself,
                // is the cycle.
                $cycle = array_slice($path, (int) array_search($dependency, $path, true));
                $cycle[] = $dependency;

                throw new InvalidArgumentException(sprintf('Cycle detected: %s.', implode(' -> ', $cycle)));
            }

            // Already fully processed dependencies need no second visit.
            if (($state[$dependency] ?? null) !== 'visited') {
                $this->visit($dependency, $blueprints, $state, $order, $path);
            }
        }

        // Weak dependencies only need to exist: never visited, so they impose
        // no order and can form cycles.
        foreach ($this->weakDependenciesOf($blueprints[$identifier]) as $dependency) {
            if (!isset($blueprints[$dependency]) && !$this->registry->has($dependency)) {
                throw new InvalidArgumentException(sprintf(
                    '"%s" is referenced as a Reference or OwningReference target by "%s" but was neither given to compile() nor already compiled this request.',
                    $dependency,
                    $identifier,
                ));
            }
        }

        array_pop($path);
        $state[$identifier] = 'visited';
        // Every dependency is already in $order by this point, so appending here
        // keeps the ordering guarantee intact.
        $order[$identifier] = $blueprints[$identifier];
    }

    /**
     * This blueprint's own direct dependencies: its parent, if any, plus every
     * Embed target among its own fields.
     * @return string[]
     */
    private function dependenciesOf(EntityBlueprint $blueprint): array {
        $dependencies = $blueprint->parent !== null ? [$blueprint->parent] : [];

        foreach ($blueprint->fields as $field) {
            $target = $this->embedTargetOf($field);
            if ($target !== null) {
                $dependencies[] = $target;
            }
        }

        return $dependencies;
    }

    /** This field's own Embed target, found through a Collection's item if needed, or null. */
    private function embedTargetOf(FieldDescriptor $field): ?string {
        return match ($field->kind()) {
            FieldKind::Embed => $field->target(),
            FieldKind::Collection => $this->embedTargetOf($field->itemDescriptor()),
            default => null,
        };
    }

    /**
     * This blueprint's own weak dependencies: every Reference or
     * OwningReference target among its own fields. They must exist, but impose
     * no order.
     * @return string[]
     */
    private function weakDependenciesOf(EntityBlueprint $blueprint): array {
        $dependencies = [];

        foreach ($blueprint->fields as $field) {
            $target = $this->weakTargetOf($field);
            if ($target !== null) {
                $dependencies[] = $target;
            }
        }

        return $dependencies;
    }

    /** This field's own Reference or OwningReference target, found through a Collection's item if needed, or null. */
    private function weakTargetOf(FieldDescriptor $field): ?string {
        return match ($field->kind()) {
            FieldKind::Reference, FieldKind::OwningReference => $field->target(),
            FieldKind::Collection => $this->weakTargetOf($field->itemDescriptor()),
            default => null,
        };
    }

    // ~Topologial Sorting
    /*================================================================================================================*/
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
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

            $this->registry->register($schemas[$identifier]);
        }

        // Only once every schema in this batch is registered: every lookup
        // below then goes through the registry and sees this batch's own
        // schemas, never one left over from an earlier compile() call.
        foreach ($order as $identifier => $blueprint) {
            $valuesByPath = [];
            $this->flattenValues($blueprint->fields, $blueprint->defaultValues, '', $valuesByPath);
            $this->valueStore->save($identifier, $valuesByPath);
        }

        return $schemas;
    }

    /*================================================================================================================*/
    // Value Flattening

    /**
     * Writes $values into $valuesByPath, one entry per leaf: an Embed's fields
     * joined with "_", an OwningReference or a Collection item traversed with ".".
     * @param array<string, mixed> $values Keyed by FieldName.
     * @param array<string, mixed> $valuesByPath Keyed by FieldPath.
     */
    private function flattenValues(FieldDescriptorCollection $fields, array $values, string $prefix, array &$valuesByPath): void {
        foreach ($fields as $name => $field) {
            $this->flattenValue($field, $values[(string) $name] ?? null, $prefix . $name, $valuesByPath);
        }
    }

    /** 
     * Dispatch correct 'flatten' method based on field kind.
     * @param array<string, mixed> $valuesByPath Keyed by FieldPath. 
     */
    private function flattenValue(FieldDescriptor $field, mixed $value, string $pathKey, array &$valuesByPath): void {
        match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => $valuesByPath[$pathKey] = $value,
            FieldKind::Embed => $this->flattenValues($this->hierarchyFieldsOf($field->target()), $value ?? [], $pathKey . '_', $valuesByPath),
            FieldKind::OwningReference => $this->flattenOwned($value, $pathKey, $valuesByPath),
            FieldKind::Collection => $this->flattenCollection($field->itemDescriptor(), $value ?? [], $pathKey, $valuesByPath),
        };
    }

    /**
     * The bare key holds the concrete class, or null: an OwningReference has
     * no value of its own there otherwise, and unlike Embed its class isn't
     * fixed by the schema.
     * @param array{class: class-string, fields: array<string, mixed>}|null $value
     * @param array<string, mixed> $valuesByPath Keyed by FieldPath.
     */
    private function flattenOwned(?array $value, string $pathKey, array &$valuesByPath): void {
        $valuesByPath[$pathKey] = $value['class'] ?? null;

        if ($value !== null) {
            $this->flattenValues($this->hierarchyFieldsOf($value['class']), $value['fields'], $pathKey . '.', $valuesByPath);
        }
    }

    /**
     * Each item is its own row in a child table, so it's traversed with "."
     * and its index, never joined into the owner's own columns with "_".
     * @param mixed[] $items
     * @param array<string, mixed> $valuesByPath Keyed by FieldPath.
     */
    private function flattenCollection(FieldDescriptor $item, array $items, string $pathKey, array &$valuesByPath): void {
        foreach (array_values($items) as $index => $value) {
            $itemKey = $pathKey . '.' . $index;

            if ($item->kind() === FieldKind::Embed) {
                $this->flattenValues($this->hierarchyFieldsOf($item->target()), $value ?? [], $itemKey . '.', $valuesByPath);
            } else {
                $this->flattenValue($item, $value, $itemKey, $valuesByPath);
            }
        }
    }

    /** $identifier's own fields plus everything it inherits, all the way up. */
    private function hierarchyFieldsOf(string $identifier): FieldDescriptorCollection {
        $schema = $this->registry->get($identifier);
        $inherited = $schema->parent !== null ? $this->hierarchyFieldsOf($schema->parent) : FieldDescriptorCollection::empty();

        return $inherited->merge($schema->fields);
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
     * $blueprints and isn't already registered from an earlier compile() call, or
     * if one of its dependencies is already being processed higher up the same
     * call stack (a cycle).
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

    // ~Topologial Sorting
    /*================================================================================================================*/
}

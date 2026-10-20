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
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldValuesBuilder;
use XyloIsCoding\CoconutCms\Persistence\Schema\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoFieldValueException;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Value\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Value\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Value\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Value\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Value\ValueNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaRegistry;

/**
 * Turns a batch of already-read blueprints into their final EntitySchemas:
 * orders the batch so a parent or Embed target always comes before whatever
 * depends on it, registers every result with the request's SchemaRegistry, and
 * writes to SchemaFieldValueStore only the default values each one changes
 * from what it would otherwise resolve to. A dependency
 * outside the given batch is expected to already be registered with the
 * registry, from an earlier compile() call.
 */
final class SchemaCompiler
{
    private readonly FieldValueResolver $resolver;

    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly SchemaFieldValueStore $valueStore,
    ) {
        $this->resolver = new FieldValueResolver($registry, $valueStore);
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

        $lookup = new SchemaLookup($this->registry, $schemas);

        // Every value each class's default instance holds, inherited fields
        // included. For a class in this batch, these are its effective values.
        $denseByIdentifier = [];
        foreach ($order as $identifier => $blueprint) {
            $dense = new FieldValuesBuilder();
            $this->flattenValues($lookup->hierarchyFieldsOf($identifier), $blueprint->defaultValues, FieldPath::of(...), $lookup, $dense);
            $denseByIdentifier[$identifier] = $dense->build();
        }

        // We only want to store values that differ from what FieldValueResolver would otherwise already resolve to.
        // This batch's own schemas and values take priority over the registry and the store:
        // they're the new versions, and  neither is committed yet.
        $resolver = $this->resolver->withBatch($schemas, $denseByIdentifier);
        $sparseByIdentifier = [];
        foreach ($order as $identifier => $blueprint) {
            $sparse = new FieldValuesBuilder();
            $this->keepValues($identifier, $lookup->hierarchyFieldsOf($identifier), $blueprint->defaultValues, FieldPath::of(...), $lookup, $resolver, $denseByIdentifier[$identifier], $sparse);
            $sparseByIdentifier[$identifier] = $sparse->build();
        }

        // Commit. The store goes first: it's the only step that can still
        // fail, and registering can't, so a failed write registers nothing.
        $this->valueStore->saveAll($sparseByIdentifier);
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
     * @param Closure(FieldName): FieldPath $pathOf Turns one of $fields' names into its full path.
     * @param SchemaLookup $schemas This batch's own schemas, ahead of the registry.
     */
    private function flattenValues(FieldDescriptorCollection $fields, ObjectNode $values, Closure $pathOf, SchemaLookup $schemas, FieldValuesBuilder $valuesByPath): void {
        foreach ($fields as $name => $field) {
            $this->flattenValue($field, $values->find($name), $pathOf($name), $schemas, $valuesByPath);
        }
    }

    /**
     * Dispatch correct 'flatten' method based on field kind.
     * @param SchemaLookup $schemas This batch's own schemas, ahead of the registry.
     */
    private function flattenValue(FieldDescriptor $field, ?ValueNode $value, FieldPath $path, SchemaLookup $schemas, FieldValuesBuilder $valuesByPath): void {
        match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => $valuesByPath->set($path, LeafNode::valueOf($value)),
            FieldKind::Embed => $this->flattenValues($schemas->hierarchyFieldsOf($field->target()), ObjectNode::from($value) ?? ObjectNode::empty(), $path->embed(...), $schemas, $valuesByPath),
            FieldKind::OwningReference => $this->flattenOwned($field, OwnedNode::from($value), $path, $schemas, $valuesByPath),
            FieldKind::Collection => $this->flattenCollection($field->itemDescriptor(), CollectionNode::from($value)?->items ?? [], $path, $schemas, $valuesByPath),
        };
    }

    /**
     * The bare path holds the concrete class, or null: an OwningReference has
     * no value of its own there otherwise, and unlike Embed its class isn't
     * fixed by the schema.
     * @param SchemaLookup $schemas This batch's own schemas, ahead of the registry.
     */
    private function flattenOwned(FieldDescriptor $field, ?OwnedNode $value, FieldPath $path, SchemaLookup $schemas, FieldValuesBuilder $valuesByPath): void {
        $valuesByPath->set($path, $value?->class);

        if ($value !== null) {
            $this->assertIsOrExtends($value->class, $field->target(), $path, $schemas);
            $this->flattenValues($schemas->hierarchyFieldsOf($value->class), $value->fields, $path->traverse(...), $schemas, $valuesByPath);
        }
    }

    /**
     * Throws unless $class exists and is $target or one of its descendants.
     * Walks parents through the schemas rather than using is_a(), since an
     * editor-created identifier isn't a PHP class.
     * @param SchemaLookup $schemas This batch's own schemas, ahead of the registry.
     */
    private function assertIsOrExtends(string $class, string $target, FieldPath $path, SchemaLookup $schemas): void {
        if (!$schemas->has($class)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" holds a "%s", which was neither given to compile() nor already compiled this request.',
                $path,
                $class,
            ));
        }

        for ($current = $class; $current !== null; $current = $schemas->get($current)->parent) {
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
     * The bare path holds the item count: a Collection has no value of its own
     * there otherwise, and without it nothing would say how many items exist.
     * Each item is its own row in a child table, so it's traversed with "."
     * and its index, never embedded into the owner with ":".
     * @param list<ValueNode|null> $items
     * @param SchemaLookup $schemas This batch's own schemas, ahead of the registry.
     */
    private function flattenCollection(FieldDescriptor $item, array $items, FieldPath $path, SchemaLookup $schemas, FieldValuesBuilder $valuesByPath): void {
        $valuesByPath->set($path, count($items));

        foreach (array_values($items) as $index => $value) {
            $itemPath = $path->traverse($index);

            if ($item->kind() === FieldKind::Embed) {
                $this->flattenValues($schemas->hierarchyFieldsOf($item->target()), ObjectNode::from($value) ?? ObjectNode::empty(), $itemPath->traverse(...), $schemas, $valuesByPath);
            } else {
                $this->flattenValue($item, $value, $itemPath, $schemas, $valuesByPath);
            }
        }
    }

    // ~Value Flattening
    /*================================================================================================================*/

    /*================================================================================================================*/
    // Sparse Values

    /**
     * Writes into $kept only what $identifier's own values change: a value is
     * kept when nothing else would supply it, or what would differs. Walks the
     * same shape flattenValues() does.
     * @param Closure(FieldName): FieldPath $pathOf Turns one of $fields' names into its full path.
     * @param SchemaLookup $schemas This batch's own schemas, ahead of the registry.
     * @param FieldValues $dense $identifier's own full values.
     */
    private function keepValues(string $identifier, FieldDescriptorCollection $fields, ObjectNode $values, Closure $pathOf, SchemaLookup $schemas, FieldValueResolver $resolver, FieldValues $dense, FieldValuesBuilder $kept): void {
        foreach ($fields as $name => $field) {
            $this->keepValue($identifier, $field, $values->find($name), $pathOf($name), $schemas, $resolver, $dense, $kept);
        }
    }

    /**
     * Dispatch correct 'keep' method based on field kind.
     * @param SchemaLookup $schemas This batch's own schemas, ahead of the registry.
     * @param FieldValues $dense $identifier's own full values.
     */
    private function keepValue(string $identifier, FieldDescriptor $field, ?ValueNode $value, FieldPath $path, SchemaLookup $schemas, FieldValueResolver $resolver, FieldValues $dense, FieldValuesBuilder $kept): void {
        match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => $this->keepIfDiffers($identifier, $path, LeafNode::valueOf($value), $resolver, $kept),
            FieldKind::Embed => $this->keepValues($identifier, $schemas->hierarchyFieldsOf($field->target()), ObjectNode::from($value) ?? ObjectNode::empty(), $path->embed(...), $schemas, $resolver, $dense, $kept),
            FieldKind::OwningReference => $this->keepOwned($identifier, OwnedNode::from($value), $path, $schemas, $resolver, $dense, $kept),
            FieldKind::Collection => $this->keepCollection($identifier, $field->itemDescriptor(), CollectionNode::from($value)?->items ?? [], $path, $schemas, $resolver, $dense, $kept),
        };
    }

    /**
     * The class it holds is kept or not like any other value; so is each of
     * its fields, against what the class would supply on its own.
     * @param SchemaLookup $schemas This batch's own schemas, ahead of the registry.
     * @param FieldValues $dense $identifier's own full values.
     */
    private function keepOwned(string $identifier, ?OwnedNode $value, FieldPath $path, SchemaLookup $schemas, FieldValueResolver $resolver, FieldValues $dense, FieldValuesBuilder $kept): void {
        $this->keepIfDiffers($identifier, $path, $value?->class, $resolver, $kept);

        if ($value !== null) {
            $this->keepValues($identifier, $schemas->hierarchyFieldsOf($value->class), $value->fields, $path->traverse(...), $schemas, $resolver, $dense, $kept);
        }
    }

    /**
     * All or nothing: kept whole, count and every item, when anything about it
     * differs from the collection it would otherwise resolve to; otherwise
     * nothing of it is. Within a kept item, each of its own fields is still
     * kept only when it differs from what the item's class would supply.
     * @param list<ValueNode|null> $items
     * @param SchemaLookup $schemas This batch's own schemas, ahead of the registry.
     * @param FieldValues $dense $identifier's own full values.
     */
    private function keepCollection(string $identifier, FieldDescriptor $item, array $items, FieldPath $path, SchemaLookup $schemas, FieldValueResolver $resolver, FieldValues $dense, FieldValuesBuilder $kept): void {
        if (!$this->collectionDiffers($identifier, $path, $resolver, $dense)) {
            return;
        }

        $kept->set($path, count($items));

        foreach (array_values($items) as $index => $value) {
            $itemPath = $path->traverse($index);

            match ($item->kind()) {
                FieldKind::Embed => $this->keepValues($identifier, $schemas->hierarchyFieldsOf($item->target()), ObjectNode::from($value) ?? ObjectNode::empty(), $itemPath->traverse(...), $schemas, $resolver, $dense, $kept),
                FieldKind::OwningReference => $this->keepOwnedItem($identifier, OwnedNode::from($value), $itemPath, $schemas, $resolver, $dense, $kept),
                default => $kept->set($itemPath, LeafNode::valueOf($value)),
            };
        }
    }

    /**
     * Like keepOwned(), except the class is always kept: an item belongs to a
     * kept collection, which has nothing else to supply it.
     * @param SchemaLookup $schemas This batch's own schemas, ahead of the registry.
     * @param FieldValues $dense $identifier's own full values.
     */
    private function keepOwnedItem(string $identifier, ?OwnedNode $value, FieldPath $itemPath, SchemaLookup $schemas, FieldValueResolver $resolver, FieldValues $dense, FieldValuesBuilder $kept): void {
        $kept->set($itemPath, $value?->class);

        if ($value !== null) {
            $this->keepValues($identifier, $schemas->hierarchyFieldsOf($value->class), $value->fields, $itemPath->traverse(...), $schemas, $resolver, $dense, $kept);
        }
    }

    /**
     * Whether the collection at $path, count and every item, differs from the
     * one $identifier would resolve to without it.
     * @param FieldValues $dense $identifier's own full values.
     */
    private function collectionDiffers(string $identifier, FieldPath $path, FieldValueResolver $resolver, FieldValues $dense): bool {
        // $dense lists a collection's count before its items, so a different
        // count is caught before an item past the other collection's end is
        // ever looked up.
        foreach ($dense->under($path) as $densePath => $value) {
            try {
                $fallback = $resolver->resolveWithoutSubtree($identifier, $densePath, $path);
            } catch (InvalidArgumentException) {
                // Nothing to fall back to, or an item whose class the other
                // collection's doesn't share: either way, not the same.
                return true;
            }

            if ($fallback !== $value) {
                return true;
            }
        }

        return false;
    }

    private function keepIfDiffers(string $identifier, FieldPath $path, mixed $value, FieldValueResolver $resolver, FieldValuesBuilder $kept): void {
        try {
            $fallback = $resolver->resolveFallback($identifier, $path);
        } catch (NoFieldValueException) {
            $kept->set($path, $value);

            return;
        }

        if ($fallback !== $value) {
            $kept->set($path, $value);
        }
    }

    // ~Sparse Values
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

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Resolver;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;

/**
 * Finds the effective value of one FieldPath on one identifier, native or
 * editor-created alike.
 *
 * Works through ValueSources, checked in order, the first one holding the key
 * winning. An identifier's own sources are itself and its parents, with no
 * prefix. Each step down the path extends them:
 * - Embed: the same sources under "field:", then the target's own sources.
 * - OwningReference: the same sources under "field.", then the own sources of
 *   whatever class the field holds, itself resolved from the same sources.
 * - Collection: the first source holding the bare field owns the collection,
 *   its value being the item count. An item's own sources are only that owner
 *   under "field.index.", then the item class's own sources: never another
 *   source's items, so a collection is never inherited item by item.
 */
final class FieldValueResolver
{
    private readonly SchemaLookup $schemas;

    /**
     * @param array<string, EntitySchema> $batchSchemas Read ahead of the registry.
     * @param array<string, FieldValues> $batchValues Read ahead of the store,
     *        keyed by identifier.
     */
    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly SchemaFieldValueStore $store,
        array $batchSchemas = [],
        private readonly array $batchValues = [],
    ) {
        $this->schemas = new SchemaLookup($registry, $batchSchemas);
    }

    /**
     * This resolver, reading $schemas and $valuesByIdentifier ahead of the
     * registry and the store: for a batch being compiled, not committed yet.
     * @param array<string, EntitySchema> $schemas
     * @param array<string, FieldValues> $valuesByIdentifier
     */
    public function withBatch(array $schemas, array $valuesByIdentifier): self {
        return new self($this->registry, $this->store, $schemas, $valuesByIdentifier);
    }

    /** Throws NoFieldValueException when nothing holds a value for $path. */
    public function resolve(string $identifier, FieldPath $path): mixed {
        return $this->resolveOn($this->chainOf($identifier), $identifier, $path, null);
    }

    /**
     * What resolve() would return if $identifier held nothing at $path.
     * Everything else $identifier holds still counts: the class its
     * OwningReference holds, for instance, when $path goes through it.
     */
    public function resolveFallback(string $identifier, FieldPath $path): mixed {
        return $this->resolveOn($this->chainOf($identifier), $identifier, $path, ValueExclusion::at($identifier, $path));
    }

    /**
     * What resolve() would return for $path if $identifier held nothing at
     * $subtree nor anything under it: e.g. a collection's item, with the
     * whole collection gone.
     */
    public function resolveWithoutSubtree(string $identifier, FieldPath $path, FieldPath $subtree): mixed {
        return $this->resolveOn($this->chainOf($identifier), $identifier, $path, ValueExclusion::under($identifier, $subtree));
    }

    /**
     * @param list<ValueSource> $sources In order.
     * @param string $class Whose fields $path's head is one of.
     */
    private function resolveOn(array $sources, string $class, FieldPath $path, ?ValueExclusion $excluded): mixed {
        $head = $path->head();
        $field = $this->fieldOf($class, $head, $path);
        $key = FieldPath::of($head);

        return match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => $path->tail() === null
                ? $this->firstValue($sources, $key, $excluded, $class, $path)
                : throw $this->invalidPath($class, $path, sprintf('"%s" has no fields of its own', $head)),
            FieldKind::Embed => $this->resolveThroughEmbed($sources, $key, $field->target(), $this->tailAfter($class, $path, FieldPath::EMBED), $excluded),
            FieldKind::OwningReference => $path->tail() === null
                ? $this->firstValue($sources, $key, $excluded, $class, $path)
                : $this->resolveThroughOwned($sources, $key, $this->tailAfter($class, $path, FieldPath::TRAVERSE), $excluded, $class, $path),
            FieldKind::Collection => $this->resolveThroughCollection($sources, $key, $field->itemDescriptor(), $class, $path, $excluded),
        };
    }

    /** @param list<ValueSource> $sources */
    private function resolveThroughEmbed(array $sources, FieldPath $key, string $target, FieldPath $rest, ?ValueExclusion $excluded): mixed {
        return $this->resolveOn([...$this->under($sources, $key, FieldPath::EMBED), ...$this->chainOf($target)], $target, $rest, $excluded);
    }

    /**
     * $rest is taken already checked, so a malformed path is reported as such
     * before anything gets looked up.
     * @param list<ValueSource> $sources
     */
    private function resolveThroughOwned(array $sources, FieldPath $key, FieldPath $rest, ?ValueExclusion $excluded, string $class, FieldPath $path): mixed {
        $held = $this->firstValue($sources, $key, $excluded, $class, $path);
        if ($held === null) {
            throw new NoFieldValueException(sprintf('Cannot resolve "%s" on "%s": "%s" holds nothing.', $path, $class, $key));
        }

        return $this->resolveOn([...$this->under($sources, $key, FieldPath::TRAVERSE), ...$this->chainOf($held)], $held, $rest, $excluded);
    }

    /** @param list<ValueSource> $sources */
    private function resolveThroughCollection(array $sources, FieldPath $key, FieldDescriptor $item, string $class, FieldPath $path, ?ValueExclusion $excluded): mixed {
        $index = null;
        $rest = null;
        if ($path->tail() !== null) {
            $itemPath = $this->tailAfter($class, $path, FieldPath::TRAVERSE);
            $index = $itemPath->head();
            if (!is_int($index)) {
                throw $this->invalidPath($class, $path, sprintf('"%s" must be followed by an item index', $key));
            }

            $rest = $itemPath->tail();
            if ($rest !== null && $item->kind() !== FieldKind::Embed && $item->kind() !== FieldKind::OwningReference) {
                throw $this->invalidPath($class, $path, sprintf('the items of "%s" have no fields of their own', $key));
            }
        }

        $owner = $this->firstSourceHolding($sources, $key, $excluded);
        if ($owner === null) {
            throw $this->noValue($class, $path);
        }

        $count = $this->valuesOf($owner->identifier)->get($owner->pathOf($key));
        if ($index === null) {
            return $count;
        }

        if ($index >= $count) {
            throw $this->invalidPath($class, $path, sprintf('"%s" has only %d item(s)', $key, $count));
        }

        $itemKey = $key->traverse($index);

        if ($rest === null) {
            return $this->firstValue([$owner], $itemKey, $excluded, $class, $path);
        }

        return $item->kind() === FieldKind::Embed
            ? $this->resolveOn([$owner->under($itemKey, FieldPath::TRAVERSE), ...$this->chainOf($item->target())], $item->target(), $rest, $excluded)
            : $this->resolveThroughOwned([$owner], $itemKey, $rest, $excluded, $class, $path);
    }

    /**
     * The value of the first source holding $key.
     * @param list<ValueSource> $sources
     */
    private function firstValue(array $sources, FieldPath $key, ?ValueExclusion $excluded, string $class, FieldPath $path): mixed {
        $source = $this->firstSourceHolding($sources, $key, $excluded);
        if ($source === null) {
            throw $this->noValue($class, $path);
        }

        return $this->valuesOf($source->identifier)->get($source->pathOf($key));
    }

    /** @param list<ValueSource> $sources */
    private function firstSourceHolding(array $sources, FieldPath $key, ?ValueExclusion $excluded): ?ValueSource {
        foreach ($sources as $source) {
            $fullPath = $source->pathOf($key);
            if ($excluded?->covers($source->identifier, $fullPath) || !$this->valuesOf($source->identifier)->has($fullPath)) {
                continue;
            }

            return $source;
        }

        return null;
    }

    /**
     * @param list<ValueSource> $sources
     * @return list<ValueSource>
     */
    private function under(array $sources, FieldPath $key, string $join): array {
        return array_map(static fn (ValueSource $source): ValueSource => $source->under($key, $join), $sources);
    }

    /**
     * $identifier and each of its parents, with no prefix.
     * @return list<ValueSource>
     */
    private function chainOf(string $identifier): array {
        $chain = [];
        for ($current = $identifier; $current !== null; $current = $this->schemas->get($current)->parent) {
            $chain[] = ValueSource::of($current);
        }

        return $chain;
    }

    private function valuesOf(string $identifier): FieldValues {
        return $this->batchValues[$identifier] ?? $this->store->load($identifier);
    }

    /** $path's tail, after checking $path's head joins to it with $join. */
    private function tailAfter(string $class, FieldPath $path, string $join): FieldPath {
        if ($path->headJoin() !== $join) {
            throw $this->invalidPath($class, $path, sprintf('"%s" must be followed by "%s"', $path->head(), $join));
        }

        return $path->tail();
    }

    private function fieldOf(string $class, FieldName|int $head, FieldPath $path): FieldDescriptor {
        $fields = $this->schemas->hierarchyFieldsOf($class);
        if (!$head instanceof FieldName || !$fields->has($head)) {
            throw $this->invalidPath($class, $path, sprintf('"%s" has no field "%s"', $class, $head));
        }

        return $fields->get($head);
    }

    private function noValue(string $class, FieldPath $path): NoFieldValueException {
        return new NoFieldValueException(sprintf('Nothing holds a value for "%s" on "%s".', $path, $class));
    }

    private function invalidPath(string $class, FieldPath $path, string $reason): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('"%s" is not a valid path on "%s": %s.', $path, $class, $reason));
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPath;

/**
 * Finds the effective value of one FieldPath on one identifier, native or
 * editor-created alike.
 *
 * Works through sources: (identifier, key prefix) pairs, checked in order,
 * the first one holding the key winning. An identifier's own sources are
 * itself and its parents, with no prefix. Each step down the path extends
 * them:
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
     * @param array<string, array<string, mixed>> $batchValues Read ahead of the
     *        store, keyed by identifier, then by FieldPath.
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
     * Makes this resolver read $schemas and $valuesByIdentifier ahead of the
     * registry and the store: for a batch being compiled, not committed yet.
     * @param array<string, EntitySchema> $schemas
     * @param array<string, array<string, mixed>> $valuesByIdentifier
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
        $excluded = ['identifier' => $identifier, 'key' => (string) $path, 'subtree' => false];

        return $this->resolveOn($this->chainOf($identifier), $identifier, $path, $excluded);
    }

    /**
     * What resolve() would return for $path if $identifier held nothing at
     * $subtree nor anything under it: e.g. a collection's item, with the
     * whole collection gone.
     */
    public function resolveWithoutSubtree(string $identifier, FieldPath $path, FieldPath $subtree): mixed {
        $excluded = ['identifier' => $identifier, 'key' => (string) $subtree, 'subtree' => true];

        return $this->resolveOn($this->chainOf($identifier), $identifier, $path, $excluded);
    }

    /**
     * @param list<array{string, string}> $sources (identifier, key prefix) pairs, in order.
     * @param string $class Whose fields $path's head is one of.
     * @param array{identifier: string, key: string, subtree: bool}|null $excluded
     */
    private function resolveOn(array $sources, string $class, FieldPath $path, ?array $excluded): mixed {
        $head = $path->head();
        $field = $this->fieldOf($class, $head, $path);
        $key = (string) $head;

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

    /**
     * @param list<array{string, string}> $sources
     * @param array{identifier: string, key: string, subtree: bool}|null $excluded
     */
    private function resolveThroughEmbed(array $sources, string $key, string $target, FieldPath $rest, ?array $excluded): mixed {
        return $this->resolveOn([...$this->prefixed($sources, $key . FieldPath::EMBED), ...$this->chainOf($target)], $target, $rest, $excluded);
    }

    /**
     * $rest is taken already checked, so a malformed path is reported as such
     * before anything gets looked up.
     * @param list<array{string, string}> $sources
     * @param array{identifier: string, key: string, subtree: bool}|null $excluded
     */
    private function resolveThroughOwned(array $sources, string $key, FieldPath $rest, ?array $excluded, string $class, FieldPath $path): mixed {
        $held = $this->firstValue($sources, $key, $excluded, $class, $path);
        if ($held === null) {
            throw new NoFieldValueException(sprintf('Cannot resolve "%s" on "%s": "%s" holds nothing.', $path, $class, $key));
        }

        return $this->resolveOn([...$this->prefixed($sources, $key . FieldPath::TRAVERSE), ...$this->chainOf($held)], $held, $rest, $excluded);
    }

    /**
     * @param list<array{string, string}> $sources
     * @param array{identifier: string, key: string, subtree: bool}|null $excluded
     */
    private function resolveThroughCollection(array $sources, string $key, FieldDescriptor $item, string $class, FieldPath $path, ?array $excluded): mixed {
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

        [$ownerIdentifier, $prefix] = $owner;
        $count = $this->valuesOf($ownerIdentifier)[$prefix . $key];
        if ($index === null) {
            return $count;
        }

        if ($index >= $count) {
            throw $this->invalidPath($class, $path, sprintf('"%s" has only %d item(s)', $key, $count));
        }

        $itemKey = $key . FieldPath::TRAVERSE . $index;
        $ownerOnly = [[$ownerIdentifier, $prefix]];

        if ($rest === null) {
            return $this->firstValue($ownerOnly, $itemKey, $excluded, $class, $path);
        }

        return $item->kind() === FieldKind::Embed
            ? $this->resolveOn([...$this->prefixed($ownerOnly, $itemKey . FieldPath::TRAVERSE), ...$this->chainOf($item->target())], $item->target(), $rest, $excluded)
            : $this->resolveThroughOwned($ownerOnly, $itemKey, $rest, $excluded, $class, $path);
    }

    /**
     * The value of the first source holding $key.
     * @param list<array{string, string}> $sources
     * @param array{identifier: string, key: string, subtree: bool}|null $excluded
     */
    private function firstValue(array $sources, string $key, ?array $excluded, string $class, FieldPath $path): mixed {
        $source = $this->firstSourceHolding($sources, $key, $excluded);
        if ($source === null) {
            throw $this->noValue($class, $path);
        }

        [$identifier, $prefix] = $source;

        return $this->valuesOf($identifier)[$prefix . $key];
    }

    /**
     * @param list<array{string, string}> $sources
     * @param array{identifier: string, key: string, subtree: bool}|null $excluded
     * @return array{string, string}|null
     */
    private function firstSourceHolding(array $sources, string $key, ?array $excluded): ?array {
        foreach ($sources as [$identifier, $prefix]) {
            $fullKey = $prefix . $key;
            if ($this->isExcluded($identifier, $fullKey, $excluded) || !array_key_exists($fullKey, $this->valuesOf($identifier))) {
                continue;
            }

            return [$identifier, $prefix];
        }

        return null;
    }

    /** @param array{identifier: string, key: string, subtree: bool}|null $excluded */
    private function isExcluded(string $identifier, string $fullKey, ?array $excluded): bool {
        if ($excluded === null || $identifier !== $excluded['identifier']) {
            return false;
        }

        return $fullKey === $excluded['key']
            || ($excluded['subtree'] && (
                str_starts_with($fullKey, $excluded['key'] . FieldPath::TRAVERSE)
                || str_starts_with($fullKey, $excluded['key'] . FieldPath::EMBED)
            ));
    }

    /**
     * @param list<array{string, string}> $sources
     * @return list<array{string, string}>
     */
    private function prefixed(array $sources, string $addition): array {
        return array_map(static fn (array $source): array => [$source[0], $source[1] . $addition], $sources);
    }

    /**
     * $identifier and each of its parents, with no prefix.
     * @return list<array{string, string}>
     */
    private function chainOf(string $identifier): array {
        $chain = [];
        for ($current = $identifier; $current !== null; $current = $this->schemas->get($current)->parent) {
            $chain[] = [$current, ''];
        }

        return $chain;
    }

    /** @return array<string, mixed> */
    private function valuesOf(string $identifier): array {
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

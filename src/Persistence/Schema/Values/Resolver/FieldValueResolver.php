<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver;

use Closure;
use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * Finds the effective value of one FieldPath on one Subject: an entity type,
 * native or editor-created alike, named by its identifier, or an instance of
 * one. Reads schemas and stored values only, never a PHP class.
 *
 * Works through ValueSources, checked in order, the first one holding the key
 * winning. An identifier's own sources are itself and its parents, with no
 * prefix; an instance's are its own values, then its identifier's. Each step
 * down the path extends them:
 * - Embed: the same sources under "field:", then the target's own sources.
 * - OwningReference: the same sources under "field.", then the own sources of
 *   whatever identifier the field holds, itself resolved from the same sources.
 * - Collection: the first source holding the bare field owns the collection,
 *   its value being the item count. An item's own sources are only that owner
 *   under "field.index.", then the item identifier's own sources: never another
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
    public function resolve(Subject $subject, FieldPath $path): mixed {
        return $this->resolveOn($this->sourcesOf($subject), $subject->identifier, $path, null);
    }

    /**
     * What resolve() would return if $subject's own values held nothing at
     * $path. Everything else they hold still counts: the identifier its
     * OwningReference holds, for instance, when $path goes through it.
     */
    public function resolveFallback(Subject $subject, FieldPath $path): mixed {
        return $this->resolveOn($this->sourcesOf($subject), $subject->identifier, $path, ValueExclusion::at($subject->ownSource(), $path));
    }

    /**
     * What resolve() would return for $path if $subject's own values held
     * nothing at $subtree nor anything under it: e.g. a collection's item,
     * with the whole collection gone.
     */
    public function resolveWithoutSubtree(Subject $subject, FieldPath $path, FieldPath $subtree): mixed {
        return $this->resolveOn($this->sourcesOf($subject), $subject->identifier, $path, ValueExclusion::under($subject->ownSource(), $subtree));
    }

    /**
     * Every value $subject resolves to, as a tree shaped like its schema's
     * fields: what an object is built from. An owned object is read through
     * the identifier it resolves to holding, a Collection item by item.
     */
    public function resolveTree(Subject $subject): ObjectNode {
        return $this->treeOf($subject, $this->schemas->hierarchyFieldsOf($subject->identifier), FieldPath::of(...));
    }

    /** @param Closure(FieldName): FieldPath $pathOf Turns one of $fields' names into its full path. */
    private function treeOf(Subject $subject, FieldDescriptorCollection $fields, Closure $pathOf): ObjectNode {
        $tree = ObjectNode::empty();
        foreach ($fields as $name => $field) {
            $tree = $tree->with($name, $this->nodeOf($subject, $field, $pathOf($name)));
        }

        return $tree;
    }

    /** Dispatches on the field's kind. */
    private function nodeOf(Subject $subject, FieldDescriptor $field, FieldPath $path): ?ValueNode {
        return match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => new LeafNode($this->resolve($subject, $path)),
            FieldKind::Embed => $this->treeOf($subject, $this->schemas->hierarchyFieldsOf($field->target()), $path->embed(...)),
            FieldKind::OwningReference => $this->ownedNodeOf($subject, $path),
            FieldKind::Collection => $this->collectionNodeOf($subject, $field->itemDescriptor(), $path),
        };
    }

    /** Null when nothing is held at $path. */
    private function ownedNodeOf(Subject $subject, FieldPath $path): ?OwnedNode {
        $identifier = $this->resolve($subject, $path);

        return $identifier === null ? null : new OwnedNode($identifier, $this->treeOf($subject, $this->schemas->hierarchyFieldsOf($identifier), $path->traverse(...)));
    }

    private function collectionNodeOf(Subject $subject, FieldDescriptor $item, FieldPath $path): CollectionNode {
        $items = [];
        for ($index = 0; $index < $this->resolve($subject, $path); $index++) {
            $itemPath = $path->traverse($index);
            $items[] = match ($item->kind()) {
                FieldKind::Embed => $this->treeOf($subject, $this->schemas->hierarchyFieldsOf($item->target()), $itemPath->traverse(...)),
                FieldKind::OwningReference => $this->ownedNodeOf($subject, $itemPath),
                default => new LeafNode($this->resolve($subject, $itemPath)),
            };
        }

        return new CollectionNode($items);
    }

    /**
     * @param list<ValueSource> $sources In order.
     * @param string $identifier Whose fields $path's head is one of.
     */
    private function resolveOn(array $sources, string $identifier, FieldPath $path, ?ValueExclusion $excluded): mixed {
        $head = $path->head();
        $field = $this->fieldOf($identifier, $head, $path);
        $key = FieldPath::of($head);

        return match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => $path->tail() === null
                ? $this->firstValue($sources, $key, $excluded, $identifier, $path)
                : throw $this->invalidPath($identifier, $path, sprintf('"%s" has no fields of its own', $head)),
            FieldKind::Embed => $this->resolveThroughEmbed($sources, $key, $field->target(), $this->tailAfter($identifier, $path, FieldPath::EMBED), $excluded),
            FieldKind::OwningReference => $path->tail() === null
                ? $this->firstValue($sources, $key, $excluded, $identifier, $path)
                : $this->resolveThroughOwned($sources, $key, $this->tailAfter($identifier, $path, FieldPath::TRAVERSE), $excluded, $identifier, $path),
            FieldKind::Collection => $this->resolveThroughCollection($sources, $key, $field->itemDescriptor(), $identifier, $path, $excluded),
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
    private function resolveThroughOwned(array $sources, FieldPath $key, FieldPath $rest, ?ValueExclusion $excluded, string $identifier, FieldPath $path): mixed {
        $held = $this->firstValue($sources, $key, $excluded, $identifier, $path);
        if ($held === null) {
            throw new NoFieldValueException(sprintf('Cannot resolve "%s" on "%s": "%s" holds nothing.', $path, $identifier, $key));
        }

        return $this->resolveOn([...$this->under($sources, $key, FieldPath::TRAVERSE), ...$this->chainOf($held)], $held, $rest, $excluded);
    }

    /** @param list<ValueSource> $sources */
    private function resolveThroughCollection(array $sources, FieldPath $key, FieldDescriptor $item, string $identifier, FieldPath $path, ?ValueExclusion $excluded): mixed {
        $index = null;
        $rest = null;
        if ($path->tail() !== null) {
            $itemPath = $this->tailAfter($identifier, $path, FieldPath::TRAVERSE);
            $index = $itemPath->head();
            if (!is_int($index)) {
                throw $this->invalidPath($identifier, $path, sprintf('"%s" must be followed by an item index', $key));
            }

            $rest = $itemPath->tail();
            if ($rest !== null && $item->kind() !== FieldKind::Embed && $item->kind() !== FieldKind::OwningReference) {
                throw $this->invalidPath($identifier, $path, sprintf('the items of "%s" have no fields of their own', $key));
            }
        }

        $owner = $this->firstSourceHolding($sources, $key, $excluded);
        if ($owner === null) {
            throw $this->noValue($identifier, $path);
        }

        $count = $this->valuesOf($owner)->get($owner->pathOf($key));
        if ($index === null) {
            return $count;
        }

        if ($index >= $count) {
            throw $this->invalidPath($identifier, $path, sprintf('"%s" has only %d item(s)', $key, $count));
        }

        $itemKey = $key->traverse($index);

        if ($rest === null) {
            return $this->firstValue([$owner], $itemKey, $excluded, $identifier, $path);
        }

        return $item->kind() === FieldKind::Embed
            ? $this->resolveOn([$owner->under($itemKey, FieldPath::TRAVERSE), ...$this->chainOf($item->target())], $item->target(), $rest, $excluded)
            : $this->resolveThroughOwned([$owner], $itemKey, $rest, $excluded, $identifier, $path);
    }

    /**
     * The value of the first source holding $key.
     * @param list<ValueSource> $sources
     */
    private function firstValue(array $sources, FieldPath $key, ?ValueExclusion $excluded, string $identifier, FieldPath $path): mixed {
        $source = $this->firstSourceHolding($sources, $key, $excluded);
        if ($source === null) {
            throw $this->noValue($identifier, $path);
        }

        return $this->valuesOf($source)->get($source->pathOf($key));
    }

    /** @param list<ValueSource> $sources */
    private function firstSourceHolding(array $sources, FieldPath $key, ?ValueExclusion $excluded): ?ValueSource {
        foreach ($sources as $source) {
            $fullPath = $source->pathOf($key);
            if ($excluded?->covers($source, $fullPath) || !$this->valuesOf($source)->has($fullPath)) {
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
     * $subject's own sources: its own values, if it's an instance, then its
     * identifier's chain.
     * @return list<ValueSource>
     */
    private function sourcesOf(Subject $subject): array {
        $chain = $this->chainOf($subject->identifier);

        return $subject->isInstance() ? [$subject->ownSource(), ...$chain] : $chain;
    }

    /**
     * $identifier and each of its parents, with no prefix.
     * @return list<ValueSource>
     */
    private function chainOf(string $identifier): array {
        $chain = [];
        for ($current = $identifier; $current !== null; $current = $this->schemas->get($current)->parent) {
            $chain[] = ValueSource::ofIdentifier($current);
        }

        return $chain;
    }

    /** What $source reads: values given directly, or an identifier's, from the batch first, then the store. */
    private function valuesOf(ValueSource $source): FieldValues {
        return $source->values ?? $this->batchValues[$source->identifier] ?? $this->store->load($source->identifier);
    }

    /** $path's tail, after checking $path's head joins to it with $join. */
    private function tailAfter(string $identifier, FieldPath $path, string $join): FieldPath {
        if ($path->headJoin() !== $join) {
            throw $this->invalidPath($identifier, $path, sprintf('"%s" must be followed by "%s"', $path->head(), $join));
        }

        return $path->tail();
    }

    private function fieldOf(string $identifier, FieldName|int $head, FieldPath $path): FieldDescriptor {
        $fields = $this->schemas->hierarchyFieldsOf($identifier);
        if (!$head instanceof FieldName || !$fields->has($head)) {
            throw $this->invalidPath($identifier, $path, sprintf('"%s" has no field "%s"', $identifier, $head));
        }

        return $fields->get($head);
    }

    private function noValue(string $identifier, FieldPath $path): NoFieldValueException {
        return new NoFieldValueException(sprintf('Nothing holds a value for "%s" on "%s".', $path, $identifier));
    }

    private function invalidPath(string $identifier, FieldPath $path, string $reason): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('"%s" is not a valid path on "%s": %s.', $path, $identifier, $reason));
    }
}

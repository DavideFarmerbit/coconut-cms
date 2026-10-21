<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver;

use Closure;
use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValuesBuilder;

/**
 * Finds what a Subject's values actually override: a value is kept when
 * nothing else would supply it, or what would differs. What isn't kept keeps
 * following whatever it falls back to. For a class, that's its default
 * values against its parents and targets; for an instance, its values
 * against its class: the touched ones.
 */
final readonly class Overrides
{
    /**
     * @param SchemaLookup $schemas Where field shapes come from.
     * @param FieldValueResolver $resolver Must already read $subject's own
     *        values: from its batch for a class being compiled, or from the
     *        Subject itself for an instance.
     */
    public function __construct(
        private SchemaLookup $schemas,
        private FieldValueResolver $resolver,
    ) {
    }

    /**
     * @param ObjectNode $values $subject's own values, as a tree.
     * @param FieldValues $dense The same values, flattened.
     */
    public function of(Subject $subject, ObjectNode $values, FieldValues $dense): FieldValues {
        $kept = new FieldValuesBuilder();
        $this->keepValues($subject, $this->schemas->hierarchyFieldsOf($subject->class), $values, FieldPath::of(...), $dense, $kept);

        return $kept->build();
    }

    /**
     * Walks the same shape ValueFlattener does.
     * @param Closure(FieldName): FieldPath $pathOf Turns one of $fields' names into its full path.
     */
    private function keepValues(Subject $subject, FieldDescriptorCollection $fields, ObjectNode $values, Closure $pathOf, FieldValues $dense, FieldValuesBuilder $kept): void {
        foreach ($fields as $name => $field) {
            $this->keepValue($subject, $field, $values->find($name), $pathOf($name), $dense, $kept);
        }
    }

    /** Dispatches on the field's kind. */
    private function keepValue(Subject $subject, FieldDescriptor $field, ?ValueNode $value, FieldPath $path, FieldValues $dense, FieldValuesBuilder $kept): void {
        match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => $this->keepIfDiffers($subject, $path, LeafNode::valueOf($value), $kept),
            FieldKind::Embed => $this->keepValues($subject, $this->schemas->hierarchyFieldsOf($field->target()), ObjectNode::from($value) ?? ObjectNode::empty(), $path->embed(...), $dense, $kept),
            FieldKind::OwningReference => $this->keepOwned($subject, OwnedNode::from($value), $path, $dense, $kept),
            FieldKind::Collection => $this->keepCollection($subject, $field->itemDescriptor(), CollectionNode::from($value)?->items ?? [], $path, $dense, $kept),
        };
    }

    /**
     * The class it holds is kept or not like any other value; so is each of
     * its fields, against what the class would supply on its own.
     */
    private function keepOwned(Subject $subject, ?OwnedNode $value, FieldPath $path, FieldValues $dense, FieldValuesBuilder $kept): void {
        $this->keepIfDiffers($subject, $path, $value?->class, $kept);

        if ($value !== null) {
            $this->keepValues($subject, $this->schemas->hierarchyFieldsOf($value->class), $value->fields, $path->traverse(...), $dense, $kept);
        }
    }

    /**
     * All or nothing: kept whole, count and every item, when anything about it
     * differs from the collection it would otherwise resolve to; otherwise
     * nothing of it is. Within a kept item, each of its own fields is still
     * kept only when it differs from what the item's class would supply.
     * @param list<ValueNode|null> $items
     */
    private function keepCollection(Subject $subject, FieldDescriptor $item, array $items, FieldPath $path, FieldValues $dense, FieldValuesBuilder $kept): void {
        if (!$this->collectionDiffers($subject, $path, $dense)) {
            return;
        }

        $kept->set($path, count($items));

        foreach (array_values($items) as $index => $value) {
            $itemPath = $path->traverse($index);

            match ($item->kind()) {
                FieldKind::Embed => $this->keepValues($subject, $this->schemas->hierarchyFieldsOf($item->target()), ObjectNode::from($value) ?? ObjectNode::empty(), $itemPath->traverse(...), $dense, $kept),
                FieldKind::OwningReference => $this->keepOwnedItem($subject, OwnedNode::from($value), $itemPath, $dense, $kept),
                default => $kept->set($itemPath, LeafNode::valueOf($value)),
            };
        }
    }

    /**
     * Like keepOwned(), except the class is always kept: an item belongs to a
     * kept collection, which has nothing else to supply it.
     */
    private function keepOwnedItem(Subject $subject, ?OwnedNode $value, FieldPath $itemPath, FieldValues $dense, FieldValuesBuilder $kept): void {
        $kept->set($itemPath, $value?->class);

        if ($value !== null) {
            $this->keepValues($subject, $this->schemas->hierarchyFieldsOf($value->class), $value->fields, $itemPath->traverse(...), $dense, $kept);
        }
    }

    /**
     * Whether the collection at $path, count and every item, differs from the
     * one $subject would resolve to without it.
     */
    private function collectionDiffers(Subject $subject, FieldPath $path, FieldValues $dense): bool {
        // $dense lists a collection's count before its items, so a different
        // count is caught before an item past the other collection's end is
        // ever looked up.
        foreach ($dense->under($path) as $densePath => $value) {
            try {
                $fallback = $this->resolver->resolveWithoutSubtree($subject, $densePath, $path);
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

    private function keepIfDiffers(Subject $subject, FieldPath $path, mixed $value, FieldValuesBuilder $kept): void {
        try {
            $fallback = $this->resolver->resolveFallback($subject, $path);
        } catch (NoFieldValueException) {
            $kept->set($path, $value);

            return;
        }

        if ($fallback !== $value) {
            $kept->set($path, $value);
        }
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values;

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

/**
 * Turns a tree of ValueNodes into dense FieldValues, one entry per leaf: an
 * Embed's fields joined with ":", an OwningReference or a Collection item
 * traversed with ".". Used for an entity type's default values and for an
 * instance's values alike.
 */
final readonly class ValueFlattener
{
    /** @param SchemaLookup $schemas Where field shapes come from: a batch being compiled first, if any. */
    public function __construct(
        private SchemaLookup $schemas,
    ) {
    }

    /** Every value $values holds for $identifier's fields, inherited ones included. */
    public function flatten(string $identifier, ObjectNode $values): FieldValues {
        $valuesByPath = new FieldValuesBuilder();
        $this->flattenValues($this->schemas->hierarchyFieldsOf($identifier), $values, FieldPath::of(...), $valuesByPath);

        return $valuesByPath->build();
    }

    /** @param Closure(FieldName): FieldPath $pathOf Turns one of $fields' names into its full path. */
    private function flattenValues(FieldDescriptorCollection $fields, ObjectNode $values, Closure $pathOf, FieldValuesBuilder $valuesByPath): void {
        foreach ($fields as $name => $field) {
            $this->flattenValue($field, $values->find($name), $pathOf($name), $valuesByPath);
        }
    }

    /** Dispatches on the field's kind. */
    private function flattenValue(FieldDescriptor $field, ?ValueNode $value, FieldPath $path, FieldValuesBuilder $valuesByPath): void {
        match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => $valuesByPath->set($path, LeafNode::valueOf($value)),
            FieldKind::Embed => $this->flattenValues($this->schemas->hierarchyFieldsOf($field->target()), ObjectNode::from($value) ?? ObjectNode::empty(), $path->embed(...), $valuesByPath),
            FieldKind::OwningReference => $this->flattenOwned($field, OwnedNode::from($value), $path, $valuesByPath),
            FieldKind::Collection => $this->flattenCollection($field->itemDescriptor(), CollectionNode::from($value)?->items ?? [], $path, $valuesByPath),
        };
    }

    /**
     * The bare path holds the concrete identifier, or null: an OwningReference
     * has no value of its own there otherwise, and unlike Embed what it holds
     * isn't fixed by the schema.
     */
    private function flattenOwned(FieldDescriptor $field, ?OwnedNode $value, FieldPath $path, FieldValuesBuilder $valuesByPath): void {
        $valuesByPath->set($path, $value?->identifier);

        if ($value !== null) {
            $this->assertIsOrExtends($value->identifier, $field->target(), $path);
            $this->flattenValues($this->schemas->hierarchyFieldsOf($value->identifier), $value->fields, $path->traverse(...), $valuesByPath);
        }
    }

    /**
     * Throws unless $identifier exists and is $target or one of its descendants.
     * Walks parents through the schemas rather than using is_a(), since an
     * editor-created identifier isn't a PHP class.
     */
    private function assertIsOrExtends(string $identifier, string $target, FieldPath $path): void {
        if (!$this->schemas->has($identifier)) {
            throw new InvalidArgumentException(sprintf(
                '"%s" holds a "%s", which was neither given to compile() nor compiled before.',
                $path,
                $identifier,
            ));
        }

        for ($current = $identifier; $current !== null; $current = $this->schemas->get($current)->parent) {
            if ($current === $target) {
                return;
            }
        }

        throw new InvalidArgumentException(sprintf(
            '"%s" holds a "%s", which is neither "%s" nor one of its descendants.',
            $path,
            $identifier,
            $target,
        ));
    }

    /**
     * The bare path holds the item count: a Collection has no value of its own
     * there otherwise, and without it nothing would say how many items exist.
     * Each item is its own row in a child table, so it's traversed with "."
     * and its index, never embedded into the owner with ":".
     * @param list<ValueNode|null> $items
     */
    private function flattenCollection(FieldDescriptor $item, array $items, FieldPath $path, FieldValuesBuilder $valuesByPath): void {
        $valuesByPath->set($path, count($items));

        foreach (array_values($items) as $index => $value) {
            $itemPath = $path->traverse($index);

            if ($item->kind() === FieldKind::Embed) {
                $this->flattenValues($this->schemas->hierarchyFieldsOf($item->target()), ObjectNode::from($value) ?? ObjectNode::empty(), $itemPath->traverse(...), $valuesByPath);
            } else {
                $this->flattenValue($item, $value, $itemPath, $valuesByPath);
            }
        }
    }
}

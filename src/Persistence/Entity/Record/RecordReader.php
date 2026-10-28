<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Record;

use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\LoadedEntity;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\Permissions;
use XyloIsCoding\CoconutCms\Persistence\Permission\TypeAction;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * Reads standalone entities as EntityRecords, for one reader: what a client
 * that knows no PHP class, the API, reads through. Follows the rules a
 * native load follows: a type the reader may not read is refused, a field it
 * may not read is left out, owned objects come with their owner.
 */
final readonly class RecordReader
{
    public function __construct(
        private EntityReader $reader,
        private SchemaLookup $schemas,
        private Permissions $permissions,
    ) {
    }

    /**
     * The standalone entity with $id; null when there's none, or when it's
     * owned: an owned one is read through its owner. Throws PermissionDenied
     * for one the reader may not read.
     */
    public function read(string $id): ?EntityRecord {
        if ($this->reader->isOwned($id) !== false) {
            return null;
        }

        $loaded = $this->reader->read($id);
        if ($loaded === null) {
            return null;
        }

        if (!$this->permissions->may($loaded->identifier, TypeAction::Read)) {
            throw PermissionDenied::ofType($loaded->identifier, TypeAction::Read);
        }

        $targets = $this->reader->identifiersOf($loaded->referencedIds);

        return $this->record($loaded->identifier, $id, $loaded->tree, null, FieldPath::TRAVERSE, $loaded, $targets);
    }

    /**
     * The record of a $type object holding $tree, at $path in the aggregate:
     * null for the root.
     * @param string $join How $path joins to the object's fields.
     * @param array<string, string> $targets Each referenced id's concrete type.
     */
    private function record(string $type, ?string $id, ObjectNode $tree, ?FieldPath $path, string $join, LoadedEntity $loaded, array $targets): EntityRecord {
        $values = [];
        foreach ($this->schemas->hierarchyFieldsOf($type) as $name => $field) {
            if (!$this->permissions->canRead($type, (string) $name)) {
                continue;
            }

            $at = $path === null ? FieldPath::of($name) : $path->append($join, FieldPath::of($name));
            $values[(string) $name] = $this->value($field, $tree->find($name), $at, $loaded, $targets);
        }

        return new EntityRecord($type, $id, $values);
    }

    /**
     * The value $node holds for $field, at $at, in record form.
     * @param array<string, string> $targets
     */
    private function value(FieldDescriptor $field, ?ValueNode $node, FieldPath $at, LoadedEntity $loaded, array $targets): mixed {
        return match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject => LeafNode::valueOf($node),
            FieldKind::Reference => $this->reference(LeafNode::valueOf($node), $targets),
            FieldKind::Embed => $node === null ? null : $this->record($field->target(), null, ObjectNode::from($node), $at, FieldPath::EMBED, $loaded, $targets),
            FieldKind::OwningReference => $this->owned(OwnedNode::from($node), $at, $loaded, $targets),
            FieldKind::Collection => $this->items($field->itemDescriptor(), CollectionNode::from($node), $at, $loaded, $targets),
        };
    }

    /**
     * Each item of a Collection, in record form. An Embed item's fields are
     * traversed, never embedded: each item is its own row.
     * @param array<string, string> $targets
     * @return list<mixed>
     */
    private function items(FieldDescriptor $item, ?CollectionNode $node, FieldPath $at, LoadedEntity $loaded, array $targets): array {
        $items = [];
        foreach ($node?->items ?? [] as $index => $itemNode) {
            $itemAt = $at->traverse($index);
            $items[] = $item->kind() === FieldKind::Embed
                ? ($itemNode === null ? null : $this->record($item->target(), null, ObjectNode::from($itemNode), $itemAt, FieldPath::TRAVERSE, $loaded, $targets))
                : $this->value($item, $itemNode, $itemAt, $loaded, $targets);
        }

        return $items;
    }

    /**
     * The owned object $node holds, with its id; null for an empty slot.
     * @param array<string, string> $targets
     */
    private function owned(?OwnedNode $node, FieldPath $at, LoadedEntity $loaded, array $targets): ?EntityRecord {
        if ($node === null) {
            return null;
        }

        return $this->record($node->identifier, $loaded->ownedIds[(string) $at] ?? null, $node->fields, $at, FieldPath::TRAVERSE, $loaded, $targets);
    }

    /**
     * What a Reference holding $id points at; null when it holds nothing, or
     * its target no longer exists.
     * @param array<string, string> $targets
     */
    private function reference(?string $id, array $targets): ?ReferenceRecord {
        if ($id === null || !isset($targets[$id])) {
            return null;
        }

        return new ReferenceRecord($targets[$id], $id);
    }
}

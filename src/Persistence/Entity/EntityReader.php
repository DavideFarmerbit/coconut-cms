<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRowsReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * Reads entities back, native or editor-created alike: an entity's stored
 * rows, with everything it didn't touch resolved from its type's defaults.
 * Reads schemas and rows only, never a PHP class: building an object from
 * what it reads is a native loader's job.
 */
final readonly class EntityReader
{
    private SchemaLookup $schemas;
    private EntityRowsReader $rows;
    private FieldValueResolver $resolver;

    public function __construct(
        private Connection $connection,
        SchemaRegistry $registry,
        SchemaFieldValueStore $store,
    ) {
        $this->schemas = new SchemaLookup($registry);
        $this->rows = new EntityRowsReader($connection, $this->schemas);
        $this->resolver = new FieldValueResolver($registry, $store);
    }

    /** Null when no entity has $id. */
    public function read(string $id): ?LoadedEntity {
        $stored = $this->rows->read($id);
        if ($stored === null) {
            return null;
        }

        $tree = $this->resolver->resolveTree(Subject::ofInstance($stored->identifier, $stored->touched));

        return new LoadedEntity($id, $stored->identifier, $tree, $stored->ownedIds, $this->referencedIdsIn($stored->identifier, $tree));
    }

    /**
     * The concrete identifier of each of $ids, in one query. An id with no
     * entity is left out.
     * @param list<string> $ids
     * @return array<string, string> Keyed by id.
     */
    public function identifiersOf(array $ids): array {
        if ($ids === []) {
            return [];
        }

        return $this->connection->fetchAllKeyValue(
            sprintf('SELECT id, concrete_identifier FROM %s WHERE id IN (?)', DatabaseSchema::ENTITIES),
            [array_values(array_unique($ids))],
            [ArrayParameterType::STRING],
        );
    }

    /** @return list<string> */
    private function referencedIdsIn(string $identifier, ObjectNode $tree): array {
        $ids = [];
        foreach ($this->schemas->hierarchyFieldsOf($identifier) as $name => $field) {
            $node = $tree->find($name);
            if ($field->kind() !== FieldKind::Collection) {
                array_push($ids, ...$this->referencedIdsInNode($field, $node));
                continue;
            }

            foreach (CollectionNode::from($node)?->items ?? [] as $item) {
                array_push($ids, ...$this->referencedIdsInNode($field->itemDescriptor(), $item));
            }
        }

        return $ids;
    }

    /**
     * The ids a Reference holds in $node, the value of $field.
     * @return list<string>
     */
    private function referencedIdsInNode(FieldDescriptor $field, ?ValueNode $node): array {
        $owned = OwnedNode::from($field->kind() === FieldKind::OwningReference ? $node : null);

        return match ($field->kind()) {
            FieldKind::Reference => LeafNode::valueOf($node) === null ? [] : [LeafNode::valueOf($node)],
            FieldKind::Embed => $this->referencedIdsIn($field->target(), ObjectNode::from($node) ?? ObjectNode::empty()),
            FieldKind::OwningReference => $owned === null ? [] : $this->referencedIdsIn($owned->identifier, $owned->fields),
            default => [],
        };
    }
}

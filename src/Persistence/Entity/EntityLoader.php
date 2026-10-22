<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use LogicException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\EntityRowsReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\StoredEntity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\IdProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeObjectBuilder;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * Loads native entities: reads an entity's stored rows, resolves everything
 * it didn't touch from its class's defaults, and builds the object. Owned
 * objects load with their owner. A Reference loads as a lazy ghost of its
 * target's concrete class, whose fields are read only when first used. One
 * object per id: loading an id already in the IdentityMap gives that object.
 */
final class EntityLoader
{
    private readonly SchemaLookup $schemas;
    private readonly EntityRowsReader $rows;
    private readonly FieldValueResolver $resolver;
    private readonly NativeObjectBuilder $builder;

    /** @var array<string, class-string> Each referenced id's concrete class, looked up while loading. */
    private array $referencedClasses = [];

    public function __construct(
        private readonly Connection $connection,
        SchemaRegistry $registry,
        SchemaFieldValueStore $store,
        private readonly IdentityMap $identities,
    ) {
        $this->schemas = new SchemaLookup($registry);
        $this->rows = new EntityRowsReader($connection, $this->schemas);
        $this->resolver = new FieldValueResolver($registry, $store);
        $this->builder = new NativeObjectBuilder($this->referenceOf(...));
    }

    /** The entity with $id, or null when there's none. */
    public function load(string $id): ?object {
        $known = $this->identities->objectOf($id);
        if ($known !== null) {
            return $known;
        }

        $stored = $this->rows->read($id);

        return $stored === null ? null : $this->build(null, $id, $stored);
    }

    /**
     * Builds entity $id from what's stored for it, into $into if given: a
     * lazy ghost being initialized.
     */
    private function build(?object $into, string $id, StoredEntity $stored): object {
        $tree = $this->resolver->resolveTree(Subject::ofInstance($stored->class, $stored->touched));
        $this->lookUpReferencedClasses($this->referencedIdsIn($stored->class, $tree));

        $object = $this->builder->build($stored->class, $tree, $into, function (FieldPath $path, object $owned) use ($stored): void {
            // An owned object with no rows of its own is still following its
            // default: it has no id until it's touched and saved.
            $ownedId = $stored->ownedIds[(string) $path] ?? null;
            if ($ownedId !== null) {
                $this->identities->remember($owned, $ownedId);
                IdProperty::assign($owned, $ownedId);
            }
        });

        $this->identities->remember($object, $id);
        IdProperty::assign($object, $id);

        return $object;
    }

    /**
     * The object a Reference holds: one already known, or a lazy ghost of the
     * target's concrete class. Null when the target no longer exists.
     */
    private function referenceOf(string $declaredClass, string $id): ?object {
        $known = $this->identities->objectOf($id);
        if ($known !== null) {
            return $known;
        }

        $class = $this->referencedClasses[$id] ?? null;
        if ($class === null) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $ghost = $reflection->newLazyGhost(function (object $ghost) use ($id): void {
            $stored = $this->rows->read($id) ?? throw new LogicException(sprintf('Entity %s was deleted before its reference was first used.', $id));
            $this->build($ghost, $id, $stored);
        });
        $this->identities->remember($ghost, $id);
        // Readable without loading the rest of it.
        IdProperty::of($class)?->setRawValueWithoutLazyInitialization($ghost, $id);

        return $ghost;
    }

    /**
     * Every id a Reference holds in $tree, owned objects and Collections included.
     * @return list<string>
     */
    private function referencedIdsIn(string $class, ObjectNode $tree): array {
        $ids = [];
        foreach ($this->schemas->hierarchyFieldsOf($class) as $name => $field) {
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
            FieldKind::OwningReference => $owned === null ? [] : $this->referencedIdsIn($owned->class, $owned->fields),
            default => [],
        };
    }

    /**
     * Looks up the concrete class of every id in $ids not known yet, in one
     * query: a ghost must be created as its final class.
     * @param list<string> $ids
     */
    private function lookUpReferencedClasses(array $ids): void {
        $unknown = array_values(array_unique(array_filter(
            $ids,
            fn (string $id): bool => !isset($this->referencedClasses[$id]) && $this->identities->objectOf($id) === null,
        )));
        if ($unknown === []) {
            return;
        }

        $rows = $this->connection->fetchAllKeyValue(
            sprintf('SELECT id, concrete_identifier FROM %s WHERE id IN (?)', DatabaseSchema::ENTITIES),
            [$unknown],
            [ArrayParameterType::STRING],
        );
        $this->referencedClasses = [...$this->referencedClasses, ...$rows];
    }
}

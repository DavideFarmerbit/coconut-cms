<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Native;

use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\StoredEntity;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueTrees;

/**
 * What native views can't see, from what's stored: what saving an aggregate
 * holding a view writes back. A native view is an object whose class stops
 * short of its entity's real type: an editor-created entity loaded as its
 * last native class. Its real type's other fields come from what's stored
 * for its id, wherever it sits now, with the owned objects under them and
 * their ids. Made under the aggregate's lock, so nothing changes it between
 * this read and the write.
 *
 * An entity not stored, or stored no longer, takes those fields from its
 * real type's defaults.
 */
final class UnseenValues
{
    /** Every value stored, as a tree; null when the aggregate isn't stored. */
    private readonly ?ObjectNode $storedTree;

    /** @var array<string, string> Each stored owned object's path, keyed by its id. */
    private readonly array $storedPaths;

    /** @var array<string, string> The id of each owned object kept under a field a view can't see, keyed by its path now. */
    private array $keptIds = [];

    /** @param string $rootId The id of the entity whose aggregate is written. */
    public function __construct(
        private readonly IdentityMap $identities,
        private readonly SchemaLookup $schemas,
        private readonly FieldValueResolver $resolver,
        private readonly string $rootId,
        private readonly ?StoredEntity $stored,
    ) {
        // Content is dense: the stored values are every value; the resolver only shapes them.
        $this->storedTree = $stored === null ? null : $resolver->resolveTree(Subject::ofInstance($stored->identifier, $stored->values));
        $this->storedPaths = $stored === null ? [] : array_flip($stored->ownedIds);
    }

    /**
     * $object's entity, $fields what its class holds, at $at in the aggregate
     * (null for the entity written itself): as its real type, every field it
     * can't see filled from what's stored.
     */
    public function complete(object $object, ObjectNode $fields, ?FieldPath $at): OwnedNode {
        $class = NativeIdentifiers::of($object::class);
        $type = $this->identities->typeOf($object) ?? $class;
        if ($type === $class) {
            return new OwnedNode($type, $fields);
        }

        [$stored, $storedAt] = $this->storedFieldsOf($object);
        $source = $stored ?? $this->resolver->resolveTree(Subject::of($type));
        $seen = $this->schemas->hierarchyFieldsOf($class);
        foreach ($this->schemas->hierarchyFieldsOf($type)->names() as $name) {
            if ($seen->has($name)) {
                continue;
            }

            $fields = $fields->with($name, $source->find($name));
            if ($stored !== null) {
                $this->keepOwnedIds($storedAt, $at, $name);
            }
        }

        return new OwnedNode($type, $fields);
    }

    /** The id of the object owned at $path now, when it's kept under a field a view can't see; null otherwise. */
    public function keptIdAt(FieldPath $path): ?string {
        return $this->keptIds[(string) $path] ?? null;
    }

    /**
     * What's stored for $object's entity, and its stored path: null for the
     * entity written itself. Both null when it isn't stored.
     * @return array{?ObjectNode, ?FieldPath}
     */
    private function storedFieldsOf(object $object): array {
        $id = $this->identities->idOf($object);
        if ($id === null || $this->storedTree === null) {
            return [null, null];
        }

        if ($id === $this->rootId) {
            return [$this->storedTree, null];
        }

        $path = $this->storedPaths[$id] ?? null;
        if ($path === null) {
            return [null, null];
        }

        $at = FieldPath::fromString($path);

        return [self::fieldsAt($this->storedTree, $at), $at];
    }

    /**
     * Notes the id of every object stored as owned under the field $name of
     * the entity stored at $storedAt, at the path it takes now under $at.
     */
    private function keepOwnedIds(?FieldPath $storedAt, ?FieldPath $at, FieldName $name): void {
        $from = $storedAt === null ? FieldPath::of($name) : $storedAt->traverse($name);
        $to = $at === null ? FieldPath::of($name) : $at->traverse($name);
        foreach ($this->stored?->ownedIds ?? [] as $path => $id) {
            $path = (string) $path;
            if ($path === (string) $from || str_starts_with($path, $from . FieldPath::TRAVERSE) || str_starts_with($path, $from . FieldPath::EMBED)) {
                $this->keptIds[$to . substr($path, strlen((string) $from))] = $id;
            }
        }
    }

    /** The fields of the owned object at $path in $tree; null when it holds none. */
    private static function fieldsAt(ObjectNode $tree, FieldPath $path): ?ObjectNode {
        $node = ValueTrees::nodeAt($tree, $path);

        return $node instanceof OwnedNode ? $node->fields : null;
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic;

use LogicException;
use WeakMap;
use WeakReference;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityLoader;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\Permissions;
use XyloIsCoding\CoconutCms\Persistence\Permission\TypeAction;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeViewClasses;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueTrees;

/**
 * Makes the DynamicEntitys of one session, and keeps one per entity: one per
 * native view object, one per id for an entity with none. What
 * PersistenceSession::loadDynamic() goes through.
 *
 * Loading reads the entity's whole aggregate once. Its native view is the
 * object load() gives, or would give. Every value its view's class can't see
 * goes into its bag, and into the bags of the objects it owns, found by id.
 */
final class DynamicEntities
{
    /** @var WeakMap<object, DynamicEntity> The DynamicEntity standing for each native view object. */
    private WeakMap $byView;

    /** @var array<string, WeakReference<DynamicEntity>> The DynamicEntity of each entity with no native view, by id. */
    private array $byId = [];

    /** @var array<string, array{ObjectNode, array<string, string>}> Each owned entity read, by id: its values, and the ids of what it owns, by path from it. */
    private array $read = [];

    /** What a value set is checked with, and put in the form a view or a bag holds. */
    public readonly DynamicValues $values;

    public function __construct(
        private readonly EntityReader $reader,
        private readonly NativeEntityLoader $loader,
        private readonly IdentityMap $identities,
        public readonly SchemaLookup $schemas,
        private readonly NativeViewClasses $classes,
        public readonly Permissions $permissions,
        private readonly FieldValueResolver $resolver,
    ) {
        $this->byView = new WeakMap();
        $this->values = new DynamicValues($schemas, $identities, $this);
    }

    /**
     * The standalone entity with $id as a DynamicEntity, whatever its type;
     * null when there's none. Throws PermissionDenied for one the reader may
     * not read; LogicException for an owned one, reached through its owner.
     */
    public function load(string $id): ?DynamicEntity {
        $known = $this->known($id);
        if ($known !== null) {
            return $known;
        }

        $owned = $this->reader->isOwned($id);
        if ($owned === null) {
            return null;
        }

        if ($owned) {
            throw new LogicException(sprintf('Entity %s is owned by another entity: load its owner, and reach it through it.', $id));
        }

        $loaded = $this->reader->read($id) ?? throw new LogicException(sprintf('Entity %s was deleted while loading it.', $id));
        if (!$this->permissions->may($loaded->identifier, TypeAction::Read)) {
            throw PermissionDenied::ofType($loaded->identifier, TypeAction::Read);
        }

        foreach ($loaded->ownedIds as $path => $ownedId) {
            $node = ValueTrees::nodeAt($loaded->tree, FieldPath::fromString((string) $path));
            if ($node instanceof OwnedNode) {
                $this->read[$ownedId] = [$node->fields, self::under($loaded->ownedIds, (string) $path)];
            }
        }

        return $this->make($loaded->identifier, $id, $this->loader->objectOf($loaded), $loaded->tree, $loaded->ownedIds);
    }

    /**
     * The DynamicEntity standing for $object, a native view an Embed or an
     * owned object holds: the one already made for it, or a new one, its bag
     * from what was read for its id. One never stored has its real type's
     * defaults there.
     */
    public function of(object $object): DynamicEntity {
        if (isset($this->byView[$object])) {
            return $this->byView[$object];
        }

        $id = $this->identities->idOf($object);
        $type = $this->identities->typeOf($object) ?? NativeIdentifiers::of($object::class);
        [$fields, $ownedIds] = $id !== null && isset($this->read[$id]) ? $this->read[$id] : [$this->resolver->resolveTree(Subject::of($type)), []];

        return $this->make($type, $id, $object, $fields, $ownedIds);
    }

    /**
     * A DynamicEntity for an Embed or an owned object only a bag holds: its
     * native view built for it when its type has a native ancestor.
     * @param array<string, string> $ownedIds What it owns, by path from it.
     */
    public function inBag(string $type, ObjectNode $fields, ?string $id, array $ownedIds): DynamicEntity {
        $view = $this->classes->lastNativeClassOf($type) === null ? null : $this->loader->buildView($type, $fields, $id, $ownedIds);

        return $this->make($type, $id, $view, $fields, $ownedIds);
    }

    /** The id of the entity $object is; null when it has none. */
    public function idOf(object $object): ?string {
        return $this->identities->idOf($object);
    }

    /**
     * The values of $object's entity, $fields, put in a new DynamicEntity:
     * every field its view's class declares from the view, the rest in its bag.
     * @param array<string, string> $ownedIds
     */
    private function make(string $type, ?string $id, ?object $view, ObjectNode $fields, array $ownedIds): DynamicEntity {
        $seen = $view === null ? null : $this->schemas->hierarchyFieldsOf(NativeIdentifiers::of($view::class));
        $bag = ObjectNode::empty();
        foreach ($this->schemas->hierarchyFieldsOf($type)->names() as $name) {
            if ($seen === null || !$seen->has($name)) {
                $bag = $bag->with($name, $fields->find($name));
            }
        }

        $entity = new DynamicEntity($this, $type, $id, $view, $bag, $ownedIds);
        if ($view !== null) {
            $this->byView[$view] = $entity;
        } elseif ($id !== null) {
            $this->byId[$id] = WeakReference::create($entity);
        }

        return $entity;
    }

    /** The DynamicEntity already standing for entity $id, if any. */
    private function known(string $id): ?DynamicEntity {
        $view = $this->identities->objectOf($id);
        if ($view !== null && isset($this->byView[$view])) {
            return $this->byView[$view];
        }

        $entity = ($this->byId[$id] ?? null)?->get();
        if ($entity === null) {
            unset($this->byId[$id]);
        }

        return $entity;
    }

    /**
     * $ownedIds under $path, keyed from it.
     * @param array<string, string> $ownedIds
     * @return array<string, string>
     */
    public static function under(array $ownedIds, string $path): array {
        $under = [];
        foreach ($ownedIds as $ownedPath => $ownedId) {
            $ownedPath = (string) $ownedPath;
            if (str_starts_with($ownedPath, $path . FieldPath::TRAVERSE) || str_starts_with($ownedPath, $path . FieldPath::EMBED)) {
                $under[substr($ownedPath, strlen($path) + 1)] = $ownedId;
            }
        }

        return $under;
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Identity;

use LogicException;
use WeakMap;
use WeakReference;

/**
 * Which object is which entity, for one request: the source of truth for an
 * object's id, whether or not its class has an #[Id] property. Holds objects
 * weakly, so remembering one never keeps it alive. One object per id: loading
 * the same entity twice gives the same object.
 */
final class IdentityMap
{
    /** @var WeakMap<object, string> */
    private WeakMap $ids;

    /** @var array<string, WeakReference<object>> */
    private array $objects = [];

    public function __construct() {
        $this->ids = new WeakMap();
    }

    /**
     * Records that $entity is the entity with $id. Throws if $entity already
     * has another id, or another live object already has $id.
     */
    public function remember(object $entity, string $id): void {
        $known = $this->ids[$entity] ?? null;
        if ($known !== null && $known !== $id) {
            throw new LogicException(sprintf('This %s is already entity %s, so it cannot also be %s.', $entity::class, $known, $id));
        }

        $other = $this->objectOf($id);
        if ($other !== null && $other !== $entity) {
            throw new LogicException(sprintf('Entity %s is already another %s object.', $id, $other::class));
        }

        $this->ids[$entity] = $id;
        $this->objects[$id] = WeakReference::create($entity);
    }

    /** $entity's id, or null when it was never saved nor loaded. */
    public function idOf(object $entity): ?string {
        return $this->ids[$entity] ?? null;
    }

    /** The live object that is entity $id, or null when there's none. */
    public function objectOf(string $id): ?object {
        $object = ($this->objects[$id] ?? null)?->get();
        if ($object === null) {
            unset($this->objects[$id]);
        }

        return $object;
    }

    /** Forgets entity $id, e.g. once it's deleted, so its id is never handed out for it again. */
    public function forget(string $id): void {
        $object = $this->objectOf($id);
        if ($object !== null) {
            unset($this->ids[$object]);
        }

        unset($this->objects[$id]);
    }
}

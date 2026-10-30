<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Native;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Rows\StoredEntity;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\IdProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * Saves and deletes native entities, through EntityWriter: the counterpart of
 * NativeEntityLoader. Hands out ids and keeps the IdentityMap and each #[Id]
 * property in step with what's stored, only once the write succeeded.
 *
 * Saves whole aggregates: an owned object is saved and deleted with its owner.
 * An owned object keeps its id for as long as it's the same object in the
 * same place; one put in its place gets a new id, and the old one is deleted.
 *
 * A native view, an editor-created entity loaded as its last native class,
 * is saved as its real type: what its class can't see is kept as stored, by
 * its id, at any depth, owned objects under it included.
 */
final readonly class NativeEntityWriter
{
    private NativeEntityValues $values;
    private SchemaLookup $schemas;
    private FieldValueResolver $resolver;

    /** @param HiddenValues|null $hidden What a load hid, written back as it was; null when nothing was. */
    public function __construct(
        private EntityWriter $writer,
        SchemaRegistry $registry,
        SchemaFieldValueStore $store,
        private IdentityMap $identities,
        private ?HiddenValues $hidden = null,
    ) {
        $this->values = new NativeEntityValues($registry, $store, $identities);
        $this->schemas = new SchemaLookup($registry);
        $this->resolver = new FieldValueResolver($registry, $store);
    }

    /**
     * Saves $entity and everything it owns. An aggregate holding a native
     * view is read once more under the writer's lock, as its real types,
     * every field a view's class can't see kept as stored.
     * @return string Its id: the one it had, or a new one.
     */
    public function save(object $entity): string {
        $id = $this->identities->idOf($entity) ?? EntityIds::generate();
        $owned = [];
        // The owned objects inside a value a load hid have no object here:
        // they keep their ids, under the path the value is written to now.
        $keptIds = [];
        $unseen = null;
        $read = function (?UnseenValues $unseen) use ($entity, &$owned, &$keptIds): EntityValues {
            $owned = [];
            $keptIds = [];
            $kept = $this->hidden === null ? null : function (object $holder, string $name, FieldPath $path) use (&$keptIds): ?ValueNode {
                [$node, $ownedIds] = $this->hidden->of($holder, $name);
                foreach ($ownedIds as $relative => $ownedId) {
                    $keptIds[(string) $path . $relative] = $ownedId;
                }

                return $node;
            };

            return $this->values->of($entity, static function (FieldPath $path, object $object) use (&$owned): void {
                $owned[(string) $path] = $object;
            }, $kept, $unseen);
        };

        $written = [$id => $entity];
        $ownedIdOf = function (FieldPath $path) use (&$owned, &$keptIds, &$unseen, &$written): string {
            $object = $owned[(string) $path] ?? null;
            if ($object === null) {
                return $keptIds[(string) $path] ?? $unseen?->keptIdAt($path) ?? EntityIds::generate();
            }

            $ownedId = $this->identities->idOf($object) ?? EntityIds::generate();
            $written[$ownedId] = $object;

            return $ownedId;
        };

        $values = $read(null);
        $deleted = !$this->holdsAView([$entity, ...array_values($owned)])
            ? $this->writer->write($values, $id, $ownedIdOf)
            : $this->writer->writeWith($id, function (?StoredEntity $stored) use ($read, $id, &$unseen): EntityValues {
                $unseen = new UnseenValues($this->identities, $this->schemas, $this->resolver, $id, $stored);

                return $read($unseen);
            }, $ownedIdOf);

        foreach ($deleted as $deletedId) {
            $this->forget($deletedId);
        }

        foreach ($written as $writtenId => $object) {
            $this->identities->remember($object, $writtenId);
            IdProperty::assign($object, $writtenId);
        }

        return $id;
    }

    /** Deletes $entity and everything it owns. Throws when it was never saved. */
    public function delete(object $entity): void {
        $id = $this->identities->idOf($entity) ?? throw new LogicException(sprintf('This %s was never saved, so there is nothing to delete.', $entity::class));

        foreach ($this->writer->delete($id) as $deletedId) {
            $this->forget($deletedId);
        }
    }

    /**
     * Whether any of $objects is a native view: an entity whose real type
     * goes deeper than its object's class.
     * @param list<object> $objects
     */
    private function holdsAView(array $objects): bool {
        foreach ($objects as $object) {
            $type = $this->identities->typeOf($object);
            if ($type !== null && $type !== NativeIdentifiers::of($object::class)) {
                return true;
            }
        }

        return false;
    }

    /** Entity $id is gone: its object, if still around, has no id anymore. */
    private function forget(string $id): void {
        $object = $this->identities->objectOf($id);
        $this->identities->forget($id);
        if ($object !== null) {
            IdProperty::assign($object, null);
        }
    }
}

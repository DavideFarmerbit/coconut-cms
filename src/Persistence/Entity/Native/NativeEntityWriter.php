<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Native;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\IdProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
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
 */
final readonly class NativeEntityWriter
{
    private NativeEntityValues $values;

    /** @param HiddenValues|null $hidden What a load hid, written back as it was; null when nothing was. */
    public function __construct(
        private EntityWriter $writer,
        SchemaRegistry $registry,
        SchemaFieldValueStore $store,
        private IdentityMap $identities,
        private ?HiddenValues $hidden = null,
    ) {
        $this->values = new NativeEntityValues($registry, $store, $identities);
    }

    /**
     * Saves $entity and everything it owns.
     * @return string Its id: the one it had, or a new one.
     */
    public function save(object $entity): string {
        $owned = [];
        // The owned objects inside a value a load hid have no object here:
        // they keep their ids, under the path the value is written to now.
        $keptIds = [];
        $kept = $this->hidden === null ? null : function (object $holder, string $name, FieldPath $path) use (&$keptIds): ?ValueNode {
            [$node, $ownedIds] = $this->hidden->of($holder, $name);
            foreach ($ownedIds as $relative => $ownedId) {
                $keptIds[(string) $path . $relative] = $ownedId;
            }

            return $node;
        };
        $values = $this->values->of($entity, static function (FieldPath $path, object $object) use (&$owned): void {
            $owned[(string) $path] = $object;
        }, $kept);

        foreach ([$entity, ...array_values($owned)] as $object) {
            $this->assertWhole($object);
        }

        $id = $this->identities->idOf($entity) ?? EntityIds::generate();
        $written = [$id => $entity];
        $deleted = $this->writer->write($values, $id, function (FieldPath $path) use ($owned, $keptIds, &$written): string {
            $object = $owned[(string) $path] ?? null;
            if ($object === null) {
                return $keptIds[(string) $path] ?? EntityIds::generate();
            }

            $ownedId = $this->identities->idOf($object) ?? EntityIds::generate();
            $written[$ownedId] = $object;

            return $ownedId;
        });

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
     * Throws when $object is the native view of an editor-created entity:
     * its class can't hold the levels its real type adds, so saving it would
     * drop them. Not built yet: the writer will keep them from what's stored.
     */
    private function assertWhole(object $object): void {
        $type = $this->identities->typeOf($object);
        if ($type !== null && $type !== NativeIdentifiers::of($object::class)) {
            throw new LogicException(sprintf(
                'This %s is the native view of a %s: saving it would drop what the %s adds, and keeping that isn\'t built yet.',
                $object::class,
                $type,
                $type,
            ));
        }
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

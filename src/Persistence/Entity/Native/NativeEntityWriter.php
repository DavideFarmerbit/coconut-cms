<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Native;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\IdProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;

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

    public function __construct(
        private EntityWriter $writer,
        SchemaRegistry $registry,
        SchemaFieldValueStore $store,
        private IdentityMap $identities,
    ) {
        $this->values = new NativeEntityValues($registry, $store, $identities);
    }

    /**
     * Saves $entity and everything it owns.
     * @return string Its id: the one it had, or a new one.
     */
    public function save(object $entity): string {
        $owned = [];
        $values = $this->values->of($entity, static function (FieldPath $path, object $object) use (&$owned): void {
            $owned[(string) $path] = $object;
        });

        $id = $this->identities->idOf($entity) ?? EntityIds::generate();
        $written = [$id => $entity];
        $deleted = $this->writer->write($values, $id, function (FieldPath $path) use ($owned, &$written): string {
            $object = $owned[(string) $path];
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

    /** Entity $id is gone: its object, if still around, has no id anymore. */
    private function forget(string $id): void {
        $object = $this->identities->objectOf($id);
        $this->identities->forget($id);
        if ($object !== null) {
            IdProperty::assign($object, null);
        }
    }
}

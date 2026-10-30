<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Native;

use Closure;
use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeValueReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Overrides;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\ValueFlattener;

/**
 * Reads a live native entity into its EntityValues. Touched is decided by
 * comparing, never by tracking writes: a field set back to what its type
 * gives counts as untouched again, and follows the type from then on. The
 * entity's class must already be compiled. A native view is read as its real
 * type, and compared with that type: a default the editor-created type gives
 * a native field isn't touched.
 */
final readonly class NativeEntityValues
{
    private SchemaLookup $schemas;
    private NativeValueReader $reader;
    private Overrides $overrides;

    /** @param IdentityMap $identities Where a referenced entity's id comes from. */
    public function __construct(
        SchemaRegistry $registry,
        SchemaFieldValueStore $store,
        private IdentityMap $identities,
    ) {
        $this->schemas = new SchemaLookup($registry);
        $this->reader = new NativeValueReader($this->referenceIdOf(...));
        $this->overrides = new Overrides($this->schemas, new FieldValueResolver($registry, $store));
    }

    /**
     * @param Closure(FieldPath, object): void|null $onOwned Told about every
     *        owned object read, with its path from $entity's class.
     * @param Closure(object, string, FieldPath): ?ValueNode|null $kept What
     *        an uninitialized field holds: the value a load hid.
     * @param UnseenValues|null $unseen What native views can't see: with it,
     *        each entity is read as its real type, every field its class
     *        lacks filled. Null when every object is what its class says.
     */
    public function of(object $entity, ?Closure $onOwned = null, ?Closure $kept = null, ?UnseenValues $unseen = null): EntityValues {
        $identifier = NativeIdentifiers::of($entity::class);
        $tree = $this->reader->read($entity, $onOwned, $kept, $unseen === null ? null : $unseen->complete(...));
        if ($unseen !== null) {
            $whole = $unseen->complete($entity, $tree, null);
            $identifier = $whole->identifier;
            $tree = $whole->fields;
        }

        $dense = (new ValueFlattener($this->schemas))->flatten($identifier, $tree);

        return new EntityValues(
            $identifier,
            $dense,
            $this->overrides->of(Subject::ofInstance($identifier, $dense), $tree, $dense),
        );
    }

    /** A Reference stores its target's id: the target must have one already. */
    private function referenceIdOf(object $target): string {
        return $this->identities->idOf($target) ?? throw new LogicException(sprintf(
            'A Reference holds a %s that was never saved: save it first, so it has an id to point at.',
            $target::class,
        ));
    }
}

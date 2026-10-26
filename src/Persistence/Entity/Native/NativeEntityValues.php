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
 * comparing, never by tracking writes: a field set back to what its class
 * gives counts as untouched again, and follows the class from then on. The
 * entity's class must already be compiled.
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
     */
    public function of(object $entity, ?Closure $onOwned = null, ?Closure $kept = null): EntityValues {
        $identifier = NativeIdentifiers::of($entity::class);
        $tree = $this->reader->read($entity, $onOwned, $kept);
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

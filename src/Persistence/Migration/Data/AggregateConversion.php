<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use Closure;
use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationPlan;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Overrides;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\StoredForm;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\ValueFlattener;

/**
 * Turns one aggregate read in the old shape into its values in the new one:
 * runs a plan's fixups, checks what they wrote, and lays it over the new
 * defaults. Writes nothing. A migration's move, its dry run and a load of a
 * left-behind aggregate all run this, so they all give the same values.
 */
final readonly class AggregateConversion
{
    private SchemaLookup $schemas;
    private FieldValueResolver $resolver;
    private StoredForm $storedForm;

    /**
     * @param array<string, EntitySchema> $target The new schemas, keyed by identifier.
     * @param array<string, FieldValues> $defaults Their stored default values, keyed by identifier.
     */
    public function __construct(
        private MigrationPlan $plan,
        private array $target,
        array $defaults,
        SchemaRegistry $registry,
        SchemaFieldValueStore $store,
    ) {
        $this->schemas = new SchemaLookup($registry, $target);
        $this->resolver = (new FieldValueResolver($registry, $store))->withBatch($target, $defaults);
        $this->storedForm = new StoredForm($this->schemas);
    }

    /** The new schemas, ahead of what's stored. */
    public function schemas(): SchemaLookup {
        return $this->schemas;
    }

    /**
     * Runs the plan over $aggregate, changing it in place, and gives what it
     * holds in the new shape; null when its type is gone.
     * @throws InvalidArgumentException When a value doesn't fit its new field.
     */
    public function convert(AggregateValues $aggregate): ?EntityValues {
        $this->plan->moveValues($aggregate);
        if (!isset($this->target[$aggregate->identifier])) {
            return null;
        }

        $this->checkWritten($aggregate);

        return $this->entityValuesOf($aggregate);
    }

    /**
     * Checks every value about to be written, touched or converted, against
     * its field in the new schemas, and puts it in canonical form. One a
     * fixup wrote where no field is fails; one only carried along there is
     * dropped, as its path is gone. An untouched value is never written: the
     * entity follows the new default, checked when it was compiled.
     */
    private function checkWritten(AggregateValues $aggregate): void {
        $values = $aggregate->values();
        $marks = $aggregate->marks();

        foreach ($this->storedForm->unknownPaths($aggregate->identifier, $values) as $path) {
            if ($marks[$path] === ValueMark::Converted) {
                throw new InvalidArgumentException(sprintf('"%s" was written where "%s" has no field.', $path, $aggregate->identifier));
            }
        }

        $written = array_values(array_map('strval', array_keys(array_filter($marks, static fn (ValueMark $mark): bool => $mark !== ValueMark::Untouched))));
        foreach ($this->storedForm->checked($aggregate->identifier, $values, $written) as $path => $value) {
            if ($value !== $values[$path]) {
                $aggregate->set(FieldPath::fromString((string) $path), $value, $marks[$path]);
            }
        }
    }

    /**
     * $aggregate's values as the new schemas store them. Only what's touched or
     * converted is compared with the new defaults, the way a save compares:
     * kept where it differs. What's untouched and unconverted stays untouched,
     * so it follows the new default, whatever it is.
     */
    private function entityValuesOf(AggregateValues $aggregate): EntityValues {
        $candidates = [];
        foreach ($aggregate->values() as $path => $value) {
            if ($aggregate->marks()[$path] !== ValueMark::Untouched) {
                $candidates[(string) $path] = $value;
            }
        }

        $tree = $this->resolver->resolveTree(Subject::ofInstance($aggregate->identifier, FieldValues::fromArray($candidates)));
        $dense = (new ValueFlattener($this->schemas))->flatten($aggregate->identifier, $tree);
        $kept = (new Overrides($this->schemas, $this->resolver))->of(Subject::ofInstance($aggregate->identifier, $dense), $tree, $dense);

        return new EntityValues($aggregate->identifier, $dense, FieldValues::fromArray(array_intersect_key($kept->toArray(), $candidates)));
    }

    /**
     * The id of the object owned at each path once $aggregate is written as
     * $values. An owned object still holding the class it held keeps its id.
     * One whose class changed, an untouched slot following a default that
     * now names another class, is another object: it gets a new id, and so
     * does everything it owns.
     * @return Closure(FieldPath): string Called owners first.
     */
    public static function ownedIdsOf(AggregateValues $aggregate, EntityValues $values): Closure {
        $replaced = [];

        return static function (FieldPath $path) use ($aggregate, $values, &$replaced): string {
            foreach ($replaced as $owner) {
                if ($path->startsWith($owner)) {
                    return EntityIds::generate();
                }
            }

            if ($aggregate->get($path) !== $values->dense->get($path)) {
                $replaced[] = $path;

                return EntityIds::generate();
            }

            return $aggregate->ownedIdAt($path) ?? EntityIds::generate();
        };
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;
use Closure;
use Throwable;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityTables;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LayoutBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateReader;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValueMark;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MovesValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\MigrationPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\Platform\DatabasePlatform;
use XyloIsCoding\CoconutCms\Persistence\Platform\Platforms;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\CompiledBatch;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\SchemaCompiler;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\StoredBlueprintReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Overrides;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\Subject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\StoredForm;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\ValueFlattener;

/**
 * Brings a database in line with the native classes in code: plans the
 * migration, then expands the tables, moves every aggregate holding a changed
 * type and stores the new schemas in one transaction, and contracts the
 * tables. Editor-created types come along: their schemas fixed by the plan,
 * compiled again over the new native ones, their content moved the same way.
 * A fresh database is a migration from nothing. One migration at a time: a second waits for
 * the first. From expand to contract, the MigrationState says a migration is
 * started, so entities can't be read or written; one that fails stays
 * started, until a migration finishes. A finished one records the code's
 * StateKey, so a request can tell whether another is pending.
 */
final readonly class Migrator
{
    /** How long a migration waits for one already running, in seconds. */
    private const LOCK_TIMEOUT = 60;

    private SchemaCompiler $compiler;
    private MigrationLog $log;
    private MigrationState $state;
    private TableChanges $tables;
    private DatabasePlatform $platform;

    public function __construct(
        private Connection $connection,
        private SchemaRegistry $registry,
        private SchemaStore $schemas,
        private SchemaFieldValueStore $values,
        private MigrationPolicy $policy = new StrictPolicy(),
    ) {
        $this->compiler = new SchemaCompiler($registry, $values, $schemas);
        $this->log = new MigrationLog($connection);
        $this->state = new MigrationState($connection);
        $this->tables = new TableChanges($connection);
        $this->platform = Platforms::of($connection);
    }

    /**
     * @param array<string, EntityBlueprint> $blueprints Every native type in
     *        code, Cms included, keyed by identifier: what
     *        NativeEntityReader::readAll() gives.
     * @param MigrationSteps $steps Every step declared, applied or not.
     */
    public function migrate(array $blueprints, MigrationSteps $steps): MigrationResult {
        if (!isset($blueprints[Cms::IDENTIFIER])) {
            throw new InvalidArgumentException(sprintf('The code must include %s, the row every entity without an owner points at.', Cms::IDENTIFIER));
        }

        $this->platform->prepare($this->connection);
        if (!$this->platform->lockMigrations($this->connection, self::LOCK_TIMEOUT)) {
            throw new RuntimeException(sprintf('Another migration has held the lock for over %d seconds.', self::LOCK_TIMEOUT));
        }

        try {
            return $this->migrateLocked($blueprints, $steps);
        } finally {
            $this->platform->unlockMigrations($this->connection);
        }
    }

    /** @param array<string, EntityBlueprint> $blueprints */
    private function migrateLocked(array $blueprints, MigrationSteps $steps): MigrationResult {
        $this->schemas->ensureTable();
        $this->values->ensureTable();
        $this->log->ensureTable();
        $this->state->ensureTable();

        $stored = $this->schemas->all();
        $fresh = $stored === [];
        $nativeBatch = $this->compiler->compute($blueprints);
        $stateKey = StateKey::of($nativeBatch, $steps);
        $pending = $fresh ? MigrationSteps::none() : $steps->pending($this->log->applied());
        $plan = (new MigrationPlanner($this->policy))->plan($stored, $nativeBatch->schemas, $pending);

        // Every editor-created type is compiled again with the new native
        // ones: checked again, its defaults laid over its new ancestors, its
        // fingerprints matching them.
        $batch = $fresh ? $nativeBatch : $this->compiler->compute([...$blueprints, ...$this->editorBlueprints($stored, $plan)]);

        // The new shape: the code's schemas, and every editor-created one as
        // the plan left it. Laid out before any DDL, so a name collision
        // fails here, naming the type.
        $target = [...$plan->schemas->schemas(), ...$batch->schemas];
        $targetSchemas = new SchemaLookup($this->registry, $target);
        $targetLevels = self::levelsOf($targetSchemas, array_keys($target));
        $targetTables = DatabaseSchema::of($targetLevels);

        $storedSchemas = new SchemaLookup($this->registry);
        $storedTables = $fresh ? [] : array_map(
            static fn ($table): string => $table->getName(),
            DatabaseSchema::of(self::levelsOf($storedSchemas, array_map(static fn (StoredSchema $each): string => $each->schema->identifier, $stored)))->getTables(),
        );

        if (!$fresh && $plan->isEmpty() && !self::anyFingerprintChanged($stored, $batch)) {
            // Nothing to move, but an earlier run may have stopped before contract.
            $this->tables->contract($targetTables, $storedTables);
            $this->state->finish($stateKey);

            return new MigrationResult(false, $plan, [], 0, $stateKey);
        }

        $this->state->start();
        $movedAside = $this->tables->expand($targetTables);
        (new EntityTables($this->connection))->seed($targetLevels);

        $recorded = $fresh ? $steps->ids() : $pending->ids();
        try {
            $moved = $this->connection->transactional(function () use ($fresh, $plan, $batch, $stored, $target, $targetSchemas, $targetLevels, $storedSchemas, $movedAside, $recorded): int {
                // Content is dense: with no fixup, a changed default still
                // rewrites every value that follows it, in every aggregate
                // holding the type.
                $moved = $fresh ? 0 : $this->moveAggregates($plan, $batch, $target, $targetSchemas, $storedSchemas, $movedAside, $this->rootsHolding(self::changedTypes($stored, $batch, $plan)));
                if ($moved > 0) {
                    (new ReferenceCheck($this->connection))->assertTargetsMatch($targetLevels, $target);
                }

                $gone = array_values(array_diff(
                    array_map(static fn (StoredSchema $each): string => $each->schema->identifier, $stored),
                    array_keys($batch->schemas),
                ));
                $this->schemas->deleteAll($gone);
                $this->values->deleteAll($gone);
                $this->compiler->store($batch);
                $this->log->record($recorded);

                return $moved;
            });
        } catch (Throwable $failure) {
            // What was loaded inside the transaction may not be stored any more.
            $this->schemas->forgetLoaded();
            $this->values->forgetLoaded();
            $this->registry->forgetAll();

            throw $failure;
        }

        $this->registry->forgetAll();
        foreach ($batch->schemas as $schema) {
            $this->registry->register($schema);
        }

        $this->tables->contract($targetTables, $storedTables);
        $this->state->finish($stateKey);

        return new MigrationResult($fresh, $plan, $recorded, $moved, $stateKey);
    }

    /**
     * Every stored type whose aggregates must move, by its stored identifier:
     * its compiled schema or stored values changed, something it depends on
     * changed, it's gone, or a fixup moves its values. A stored type records
     * the fingerprint of everything it depends on, transitively, and every
     * type is compiled again, editor-created ones too: a change anywhere below
     * a type shows in its own record.
     * @param list<StoredSchema> $stored
     * @return list<string>
     */
    private static function changedTypes(array $stored, CompiledBatch $batch, MigrationPlan $plan): array {
        $compiled = [];
        foreach ($batch->stored as $each) {
            $compiled[$each->schema->identifier] = $each;
        }

        $changed = [];
        foreach ($stored as $each) {
            $now = $compiled[$each->schema->identifier] ?? null;
            if ($now === null || $now->fingerprint !== $each->fingerprint || $now->dependencies != $each->dependencies) {
                $changed[$each->schema->identifier] = true;
            }
        }

        // A fixup can move values with no schema changing: a step may be data alone.
        foreach ($plan->fixups as $planned) {
            if ($planned->fixup instanceof MovesValues) {
                foreach ($planned->fixup->movedTypes() as $type) {
                    $changed[$type] = true;
                }
            }
        }

        return array_map('strval', array_keys($changed));
    }

    /**
     * Every editor-created type stored, rebuilt to be compiled again: its
     * schema as the plan fixed it, and its own defaults moved by the plan the
     * way content is. Its default instance is one more place each fixup runs,
     * like an entity holding every default: renames, conversions and drops
     * reach what it sets itself, and what an initializer returns there is
     * kept, unless the initializer returns Conversion::default().
     * @param list<StoredSchema> $stored
     * @return array<string, EntityBlueprint> Keyed by identifier.
     */
    private function editorBlueprints(array $stored, MigrationPlan $plan): array {
        $resolver = new FieldValueResolver($this->registry, $this->values);
        $flattener = new ValueFlattener(new SchemaLookup($this->registry));

        $blueprints = [];
        foreach ($stored as $each) {
            if ($each->source !== SchemaSource::Editor) {
                continue;
            }

            $identifier = $each->schema->identifier;
            $own = $this->values->load($identifier);
            $values = [];
            $marks = [];
            foreach ($flattener->flatten($identifier, $resolver->resolveTree(Subject::of($identifier)))->toArray() as $path => $value) {
                $values[$path] = $value;
                $marks[$path] = $own->has(FieldPath::fromString($path)) ? ValueMark::Touched : ValueMark::Untouched;
            }

            $defaults = new AggregateValues($identifier, $values, $marks, []);
            $plan->moveValues($defaults);

            // What it sets itself after the fixups: what it set, and whatever
            // they converted. The compiler lays it over its new ancestors.
            $kept = [];
            foreach ($defaults->values() as $path => $value) {
                if ($defaults->marks()[$path] !== ValueMark::Untouched) {
                    $kept[$path] = $value;
                }
            }

            $blueprints[$identifier] = StoredBlueprintReader::of($plan->schemas->get($identifier), SchemaSource::Editor, FieldValues::fromArray($kept));
        }

        return $blueprints;
    }

    /**
     * The id of every root entity whose aggregate holds an entity of one of
     * $types: as the root itself, or owned at any depth. A type held only
     * through an Embed is found by its holder's dependencies instead.
     * @param list<string> $types
     * @return list<string> In id order.
     */
    private function rootsHolding(array $types): array {
        if ($types === []) {
            return [];
        }

        // Walks up from each entity of the types to its root: a standalone
        // entity is owned by the Cms row through no field.
        return $this->connection->fetchFirstColumn(sprintf(
            'WITH RECURSIVE up (id, owner, owner_field) AS ('
            . ' SELECT id, owner, owner_field FROM %1$s WHERE concrete_identifier IN (?)'
            . ' UNION'
            . ' SELECT holder.id, holder.owner, holder.owner_field FROM %1$s holder JOIN up ON holder.id = up.owner'
            . " WHERE up.owner_field <> ''"
            . ") SELECT id FROM up WHERE owner_field = '' ORDER BY id",
            $this->connection->quoteSingleIdentifier(DatabaseSchema::ENTITIES),
        ), [$types], [ArrayParameterType::STRING]);
    }

    /**
     * Reads each aggregate in $roots with the stored schemas, runs the plan
     * over its values, and writes it in the new shape; deletes one rooted at
     * a type that's gone.
     * @param array<string, EntitySchema> $target
     * @param list<string> $movedAside
     * @param list<string> $roots The aggregates the migration changes, by their root's id.
     * @return int How many aggregates moved.
     */
    private function moveAggregates(MigrationPlan $plan, CompiledBatch $batch, array $target, SchemaLookup $targetSchemas, SchemaLookup $storedSchemas, array $movedAside, array $roots): int {
        $reader = new AggregateReader($this->connection, $this->registry, $movedAside);
        $writer = EntityWriter::forMigration($this->connection, $targetSchemas, $storedSchemas);
        $resolver = (new FieldValueResolver($this->registry, $this->values))->withBatch($target, $batch->values);
        $storedForm = new StoredForm($targetSchemas);

        foreach ($roots as $id) {
            try {
                $aggregate = $reader->read($id);
                $plan->moveValues($aggregate);

                if (!isset($target[$aggregate->identifier])) {
                    $writer->delete($id);
                    continue;
                }

                self::checkWritten($aggregate, $storedForm);
                $values = self::entityValuesOf($aggregate, $targetSchemas, $resolver);
                $writer->write($values, $id, self::ownedIdsOf($aggregate, $values));
            } catch (Throwable $failure) {
                throw new RuntimeException(sprintf('Moving entity %s failed: %s', $id, $failure->getMessage()), 0, $failure);
            }
        }

        return count($roots);
    }

    /**
     * The id of the object owned at each path once $aggregate is written as
     * $values. An owned object still holding the class it held keeps its id.
     * One whose class changed, an untouched slot following a default that
     * now names another class, is another object: it gets a new id, and so
     * does everything it owns.
     * @return Closure(FieldPath): string Called owners first.
     */
    private static function ownedIdsOf(AggregateValues $aggregate, EntityValues $values): Closure {
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

    /**
     * Checks every value about to be written, touched or converted, against
     * its field in the new schemas, and puts it in canonical form. One a
     * fixup wrote where no field is fails; one only carried along there is
     * dropped, as its path is gone. An untouched value is never written: the
     * entity follows the new default, checked when it was compiled.
     */
    private static function checkWritten(AggregateValues $aggregate, StoredForm $storedForm): void {
        $values = $aggregate->values();
        $marks = $aggregate->marks();

        foreach ($storedForm->unknownPaths($aggregate->identifier, $values) as $path) {
            if ($marks[$path] === ValueMark::Converted) {
                throw new InvalidArgumentException(sprintf('"%s" was written where "%s" has no field.', $path, $aggregate->identifier));
            }
        }

        $written = array_values(array_map('strval', array_keys(array_filter($marks, static fn (ValueMark $mark): bool => $mark !== ValueMark::Untouched))));
        foreach ($storedForm->checked($aggregate->identifier, $values, $written) as $path => $value) {
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
    private static function entityValuesOf(AggregateValues $aggregate, SchemaLookup $schemas, FieldValueResolver $resolver): EntityValues {
        $candidates = [];
        foreach ($aggregate->values() as $path => $value) {
            if ($aggregate->marks()[$path] !== ValueMark::Untouched) {
                $candidates[(string) $path] = $value;
            }
        }

        $tree = $resolver->resolveTree(Subject::ofInstance($aggregate->identifier, FieldValues::fromArray($candidates)));
        $dense = (new ValueFlattener($schemas))->flatten($aggregate->identifier, $tree);
        $kept = (new Overrides($schemas, $resolver))->of(Subject::ofInstance($aggregate->identifier, $dense), $tree, $dense);

        return new EntityValues($aggregate->identifier, $dense, FieldValues::fromArray(array_intersect_key($kept->toArray(), $candidates)));
    }

    /**
     * @param list<string> $identifiers
     * @return list<LevelLayout>
     */
    private static function levelsOf(SchemaLookup $schemas, array $identifiers): array {
        $layouts = new LayoutBuilder($schemas);

        return array_map($layouts->levelOf(...), $identifiers);
    }

    /** @param list<StoredSchema> $stored */
    private static function anyFingerprintChanged(array $stored, CompiledBatch $batch): bool {
        $fingerprints = [];
        foreach ($stored as $each) {
            $fingerprints[$each->schema->identifier] = $each->fingerprint;
        }

        foreach ($batch->stored as $each) {
            if (($fingerprints[$each->schema->identifier] ?? null) !== $each->fingerprint) {
                return true;
            }
        }

        return false;
    }
}

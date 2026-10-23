<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityTables;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LayoutBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateReader;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValueMark;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\MigrationPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\CompiledBatch;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\SchemaCompiler;
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
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\ValueFlattener;

/**
 * Brings a database in line with the native classes in code: plans the
 * migration, then expands the tables, moves every aggregate and stores the
 * new schemas in one transaction, and contracts the tables. A fresh database
 * is a migration from nothing. One migration at a time: a second waits for
 * the first.
 */
final readonly class Migrator
{
    /** The MySQL lock every migration holds while it runs. */
    private const LOCK = 'coconut-cms-migration';

    /** How long a migration waits for one already running, in seconds. */
    private const LOCK_TIMEOUT = 60;

    private SchemaCompiler $compiler;
    private MigrationLog $log;
    private TableChanges $tables;

    public function __construct(
        private Connection $connection,
        private SchemaRegistry $registry,
        private SchemaStore $schemas,
        private SchemaFieldValueStore $values,
        private MigrationPolicy $policy = new StrictPolicy(),
    ) {
        $this->compiler = new SchemaCompiler($registry, $values, $schemas);
        $this->log = new MigrationLog($connection);
        $this->tables = new TableChanges($connection);
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

        $this->lock();
        try {
            return $this->migrateLocked($blueprints, $steps);
        } finally {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }
    }

    /** @param array<string, EntityBlueprint> $blueprints */
    private function migrateLocked(array $blueprints, MigrationSteps $steps): MigrationResult {
        $this->schemas->ensureTable();
        $this->values->ensureTable();
        $this->log->ensureTable();

        $stored = $this->schemas->all();
        $fresh = $stored === [];
        $batch = $this->compiler->compute($blueprints);
        $pending = $fresh ? MigrationSteps::none() : $steps->pending($this->log->applied());
        $plan = (new MigrationPlanner($this->policy))->plan($stored, $batch->schemas, $pending);
        $this->assertNoEditorSchemaChanged($stored, $plan);

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

            return new MigrationResult(false, $plan, [], 0);
        }

        $movedAside = $this->tables->expand($targetTables);
        (new EntityTables($this->connection))->seed($targetLevels);

        $recorded = $fresh ? $steps->ids() : $pending->ids();
        try {
            $moved = $this->connection->transactional(function () use ($fresh, $plan, $batch, $stored, $target, $targetSchemas, $storedSchemas, $movedAside, $recorded): int {
                $moved = $fresh || $plan->isEmpty() ? 0 : $this->moveAggregates($plan, $batch, $target, $targetSchemas, $storedSchemas, $movedAside);

                $gone = array_values(array_diff(
                    array_map(static fn (StoredSchema $each): string => $each->schema->identifier, array_filter($stored, static fn (StoredSchema $each): bool => $each->source === SchemaSource::Native)),
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

        return new MigrationResult($fresh, $plan, $recorded, $moved);
    }

    /**
     * Reads every aggregate with the stored schemas, runs the plan over its
     * values, and writes it in the new shape; deletes one rooted at a type
     * that's gone.
     * @param array<string, EntitySchema> $target
     * @param list<string> $movedAside
     * @return int How many aggregates moved.
     */
    private function moveAggregates(MigrationPlan $plan, CompiledBatch $batch, array $target, SchemaLookup $targetSchemas, SchemaLookup $storedSchemas, array $movedAside): int {
        $reader = new AggregateReader($this->connection, $this->registry, $this->values, $movedAside);
        $writer = EntityWriter::forMigration($this->connection, $targetSchemas, $storedSchemas);
        $resolver = (new FieldValueResolver($this->registry, $this->values))->withBatch($target, $batch->values);

        $roots = $this->connection->fetchFirstColumn(sprintf("SELECT id FROM %s WHERE owner_field = '' ORDER BY id", DatabaseSchema::ENTITIES));
        foreach ($roots as $id) {
            try {
                $aggregate = $reader->read($id);
                $plan->moveValues($aggregate);

                if (!isset($target[$aggregate->identifier])) {
                    $writer->delete($id);
                    continue;
                }

                $writer->write(self::entityValuesOf($aggregate, $targetSchemas, $resolver), $id, static fn (FieldPath $path): string => $aggregate->ownedIdAt($path) ?? EntityIds::generate());
            } catch (Throwable $failure) {
                throw new RuntimeException(sprintf('Moving entity %s failed: %s', $id, $failure->getMessage()), 0, $failure);
            }
        }

        return count($roots);
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
     * Editor-created schemas are fixed by a native migration where a fixup
     * makes it obvious, but moving their stored values isn't built yet: such
     * a migration stops before changing anything.
     * @param list<StoredSchema> $stored
     */
    private function assertNoEditorSchemaChanged(array $stored, MigrationPlan $plan): void {
        foreach ($stored as $each) {
            if ($each->source === SchemaSource::Editor && ($plan->schemas->schemas()[$each->schema->identifier] ?? null) != $each->schema) {
                throw new RuntimeException(sprintf('The migration changes the editor-created type "%s": migrating editor-created types isn\'t supported yet.', $each->schema->identifier));
            }
        }
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

    private function lock(): void {
        if ((int) $this->connection->fetchOne('SELECT GET_LOCK(?, ?)', [self::LOCK, self::LOCK_TIMEOUT]) !== 1) {
            throw new RuntimeException(sprintf('Another migration has held the lock for over %d seconds.', self::LOCK_TIMEOUT));
        }
    }
}

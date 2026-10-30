<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use RuntimeException;
use Closure;
use Exception;
use Throwable;
use XyloIsCoding\CoconutCms\Persistence\Entity\Cms;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityTables;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\DatabaseSchema;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LayoutBuilder;
use XyloIsCoding\CoconutCms\Persistence\Entity\Layout\LevelLayout;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationUnfinished;
use XyloIsCoding\CoconutCms\Persistence\Entity\NoTypeCaptures;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateReader;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\ValueMark;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\BreaksNotAccepted;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSave;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSaveReport;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EntriesFailToMove;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Normalize\OperationNormalizer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\PreparedEditorSave;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\StaleSchemaRevision;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MovesValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\MigrationPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\MigrationPending;
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

    /**
     * What migrate() would do, found without changing anything: a dry run,
     * to run against production before a deploy. Takes no lock, and plans
     * to the end even when something blocks, so every block and every break
     * is reported at once.
     * @param array<string, EntityBlueprint> $blueprints The same as migrate()'s.
     */
    public function check(array $blueprints, MigrationSteps $steps): MigrationReport {
        $this->platform->prepare($this->connection);
        $stored = $this->schemas->tableExists() ? $this->schemas->all() : [];
        if ($stored === []) {
            return new MigrationReport(true, true, $steps->ids(), [], [], []);
        }

        $nativeBatch = $this->compiler->compute($blueprints);
        $pending = $steps->pending($this->log->applied());
        $draft = (new MigrationPlanner($this->policy))->draft($stored, $nativeBatch->schemas, $pending);
        $state = $this->state->read();

        return new MigrationReport(
            fresh: false,
            pending: $state['startedAt'] !== null || $state['stateKey'] !== StateKey::of($nativeBatch, $steps),
            pendingSteps: $pending->ids(),
            fixups: array_map(static fn (PlannedFixup $planned): string => $planned->fixup->describe(), $draft->plan->fixups),
            breaks: $draft->breaks,
            blocked: $draft->blocked,
        );
    }

    /**
     * Runs an editor save as a migration: its operations change the
     * editor-created types, its fixups move their content, under the
     * project's policy. The schema revision goes up.
     * @param array<string, EntityBlueprint> $blueprints The same as migrate()'s.
     * @param MigrationSteps $steps The same as migrate()'s.
     * @throws MigrationPending|MigrationUnfinished When the database doesn't match the code.
     * @throws StaleSchemaRevision When the types changed since $save's revision.
     * @throws InvalidOperation When an operation can't apply.
     * @throws MigrationBlocked When the policy blocks a break the save causes.
     * @throws BreaksNotAccepted When the save breaks a type, and $save doesn't accept it.
     * @throws EntriesFailToMove When an entry's values can't move, found before anything changes.
     */
    public function saveEditor(array $blueprints, MigrationSteps $steps, EditorSave $save): EditorSaveReport {
        $this->platform->prepare($this->connection);
        if (!$this->platform->lockMigrations($this->connection, self::LOCK_TIMEOUT)) {
            throw new RuntimeException(sprintf('Another migration has held the lock for over %d seconds.', self::LOCK_TIMEOUT));
        }

        try {
            $this->schemas->ensureTable();
            $this->values->ensureTable();
            $this->log->ensureTable();
            $this->state->ensureTable();

            $prepared = $this->prepareEditor($blueprints, $steps, $save);
            if ($prepared->plan->isBlocked()) {
                throw new MigrationBlocked($prepared->plan->blocked);
            }

            if ($prepared->plan->breaks !== [] && !$save->acceptBreaks) {
                throw new BreaksNotAccepted($prepared->plan->breaks);
            }

            if ($prepared->failures !== []) {
                throw new EntriesFailToMove($prepared->failures);
            }

            $moved = $this->run($prepared->stored, false, $prepared->plan->plan, $prepared->batch, $prepared->stateKey, []);

            return $prepared->report($this->state->revision(), $moved ?? 0);
        } finally {
            $this->platform->unlockMigrations($this->connection);
        }
    }

    /**
     * What saveEditor() would do with $save, found without changing
     * anything: the fixups, the breaks and what blocks it. Takes no lock.
     * Throws as saveEditor() does, but reports breaks and blocks instead.
     * @param array<string, EntityBlueprint> $blueprints The same as migrate()'s.
     */
    public function previewEditor(array $blueprints, MigrationSteps $steps, EditorSave $save): EditorSaveReport {
        $this->platform->prepare($this->connection);

        return $this->prepareEditor($blueprints, $steps, $save)->report($save->revision, 0);
    }

    /**
     * Normalizes $save's operations, applies them to a draft of the stored
     * editor-created types, and plans its fixups: the created types join the starting state
     * as they end up. Compiles the new schemas unless the plan is blocked.
     * @param array<string, EntityBlueprint> $blueprints
     */
    private function prepareEditor(array $blueprints, MigrationSteps $steps, EditorSave $save): PreparedEditorSave {
        $stored = $this->schemas->tableExists() ? $this->schemas->all() : [];
        $nativeBatch = $this->compiler->compute($blueprints);
        $stateKey = StateKey::of($nativeBatch, $steps);
        $state = $this->state->read();
        if ($state['startedAt'] !== null) {
            throw new MigrationUnfinished($state['startedAt']);
        }

        if ($stored === [] || $state['stateKey'] !== $stateKey) {
            throw new MigrationPending();
        }

        if ($state['revision'] !== $save->revision) {
            throw new StaleSchemaRevision($save->revision, $state['revision']);
        }

        $editor = [];
        $ownDefaults = [];
        foreach ($stored as $each) {
            if ($each->source === SchemaSource::Editor) {
                $editor[$each->schema->identifier] = $each->schema;
                $ownDefaults[$each->schema->identifier] = $this->values->load($each->schema->identifier)->toArray();
            }
        }

        // Nothing the admin undid reaches the database.
        $operations = (new OperationNormalizer($nativeBatch->schemas, $editor, $ownDefaults))->normalize($save->operations);
        $draft = new SchemaDraft($nativeBatch->schemas, $editor);
        foreach ($operations as $operation) {
            $operation->applyTo($draft);
        }

        $created = array_map(static fn (EntitySchema $schema): StoredSchema => new StoredSchema($schema, SchemaSource::Editor, '', []), array_values($draft->created()));
        $plan = (new MigrationPlanner($this->policy))->draft(
            [...$stored, ...$created],
            $nativeBatch->schemas,
            MigrationSteps::none()->add('editor-save', $draft->fixups()),
            $draft->targets(),
        );

        $batch = $plan->isBlocked() ? null : $this->compiler->compute([...$blueprints, ...$this->editorBlueprints($stored, $plan->plan, $draft)]);
        if ($batch !== null) {
            self::assertPinned($draft, $batch);
        }

        return new PreparedEditorSave($operations, $stored, $stateKey, $plan, $batch, $batch === null ? [] : $this->failuresOf($stored, $plan->plan, $batch));
    }

    /**
     * Throws when a default the save pins didn't make it into $batch: its
     * path doesn't exist once the save ran, and compiling drops it. A default
     * path follows renames of its first field only; one going into a field
     * the same save renames inside an Embed or an owned object isn't followed
     * yet. Refused, never dropped silently.
     */
    private static function assertPinned(SchemaDraft $draft, CompiledBatch $batch): void {
        foreach ($draft->defaultChanges() as $change) {
            if ($change->pins() && !array_key_exists((string) $change->path, ($batch->values[$change->type] ?? FieldValues::empty())->toArray())) {
                throw new InvalidOperation(sprintf(
                    'SetDefault(%1$s, %2$s): "%1$s" has no path "%2$s" once this save ran. A default path follows a rename of its first field only; following a rename inside it is not built yet. Save the rename first, or set the default with the new names.',
                    $change->type,
                    $change->path,
                ));
            }
        }
    }

    /**
     * Moves every aggregate the plan changes without writing anything, and
     * gives what fails: a converter cancelling, a value that doesn't fit its
     * new field. Run before an editor save touches the database, so a failure
     * leaves the site as it was.
     * @param list<StoredSchema> $stored
     * @return array<string, string> Why each failing aggregate fails, keyed by its root's id.
     */
    private function failuresOf(array $stored, MigrationPlan $plan, CompiledBatch $batch): array {
        $target = [...$plan->schemas->schemas(), ...$batch->schemas];
        $targetSchemas = new SchemaLookup($this->registry, $target);
        $reader = new AggregateReader($this->connection, $this->registry, []);
        $resolver = (new FieldValueResolver($this->registry, $this->values))->withBatch($target, $batch->values);
        $storedForm = new StoredForm($targetSchemas);

        $failures = [];
        foreach ($this->rootsHolding(self::changedTypes($stored, $batch, $plan)) as $id) {
            try {
                $aggregate = $reader->read($id);
                $plan->moveValues($aggregate);
                if (isset($target[$aggregate->identifier])) {
                    self::checkWritten($aggregate, $storedForm);
                    self::entityValuesOf($aggregate, $targetSchemas, $resolver);
                }
            } catch (Exception $failure) {
                $failures[$id] = $failure->getMessage();
            }
        }

        return $failures;
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

        $recorded = $fresh ? $steps->ids() : $pending->ids();
        $moved = $this->run($stored, $fresh, $plan, $batch, $stateKey, $recorded);

        return $moved === null
            ? new MigrationResult(false, $plan, [], 0, $stateKey)
            : new MigrationResult($fresh, $plan, $recorded, $moved, $stateKey);
    }

    /**
     * Brings the database to $batch, holding the lock: lays out the new
     * tables, expands, moves every aggregate the plan changes, stores the
     * schemas and $recorded, contracts, and raises the schema revision. Does
     * nothing but finish when nothing changed.
     * @param list<StoredSchema> $stored What's stored now.
     * @param list<string> $recorded The step ids to record as applied.
     * @return int|null How many aggregates moved; null when nothing changed.
     */
    private function run(array $stored, bool $fresh, MigrationPlan $plan, CompiledBatch $batch, string $stateKey, array $recorded): ?int {
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
            // No stored schema changed: the schema revision stays.
            $this->tables->contract($targetTables, $storedTables);
            $this->state->finish($stateKey, false);

            return null;
        }

        $this->state->start();
        $movedAside = $this->tables->expand($targetTables);
        (new EntityTables($this->connection))->seed($targetLevels);
        (new NoTypeCaptures($this->connection))->ensureTable();

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
        $this->state->finish($stateKey, true);

        return $moved;
    }

    /**
     * Every stored type whose aggregates must move, by its stored identifier:
     * its compiled schema or stored values changed, something it depends on
     * changed, it's gone, or a fixup moves the values of it or of a type it
     * depends on. A stored type records
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

        // A fixup can move values with no schema changing: a step may be data
        // alone. It moves them wherever its type lives: in the type's
        // subtypes, and in every type holding it, owned or as an Embed. Each
        // of those depends on it.
        $moved = [];
        foreach ($plan->fixups as $planned) {
            if ($planned->fixup instanceof MovesValues) {
                foreach ($planned->fixup->movedTypes() as $type) {
                    $moved[$type] = true;
                }
            }
        }

        foreach ($stored as $each) {
            if (isset($moved[$each->schema->identifier]) || array_intersect_key($each->dependencies, $moved) !== []) {
                $changed[$each->schema->identifier] = true;
            }
        }

        return array_map('strval', array_keys($changed));
    }

    /**
     * Every editor-created type stored, rebuilt to be compiled again: its
     * schema as the plan fixed it, and what it sets itself carried through
     * the plan. Renames and moves take what it set along, converters convert
     * it, drops drop it. What it inherits is never kept, converted or not,
     * and nothing an initializer writes is: it follows its new ancestors, as
     * a native subtype's defaults follow the code.
     *
     * In an editor save, $draft adds the types it creates, setting nothing
     * yet, and its default changes apply last, on the final names. A type
     * the save renamed takes its new name; one it deleted is gone.
     * @param list<StoredSchema> $stored
     * @return array<string, EntityBlueprint> Keyed by identifier.
     */
    private function editorBlueprints(array $stored, MigrationPlan $plan, ?SchemaDraft $draft = null): array {
        $resolver = new FieldValueResolver($this->registry, $this->values);
        $flattener = new ValueFlattener(new SchemaLookup($this->registry));

        $own = [];
        foreach ($stored as $each) {
            if ($each->source !== SchemaSource::Editor) {
                continue;
            }

            // Every default moves, so each fixup finds the shape it expects;
            // only what the type sets itself is kept. The compiler lays it
            // over the type's new ancestors.
            $identifier = $each->schema->identifier;
            $defaults = AggregateValues::ofDefaults(
                $identifier,
                $flattener->flatten($identifier, $resolver->resolveTree(Subject::of($identifier)))->toArray(),
                array_map('strval', array_keys($this->values->load($identifier)->toArray())),
            );
            $plan->moveValues($defaults);

            if ($plan->schemas->has($defaults->identifier)) {
                $own[$defaults->identifier] = $defaults->ownValues();
            }
        }

        foreach ($draft?->created() ?? [] as $identifier => $schema) {
            if ($plan->schemas->has($identifier)) {
                $own[$identifier] = [];
            }
        }

        foreach ($draft?->defaultChanges() ?? [] as $change) {
            if (!isset($own[$change->type])) {
                throw new InvalidOperation(sprintf('A default change names "%s", which this save leaves no type.', $change->type));
            }

            $own[$change->type] = $change->applyTo($own[$change->type]);
        }

        $blueprints = [];
        foreach ($own as $identifier => $values) {
            $blueprints[$identifier] = StoredBlueprintReader::of($plan->schemas->get($identifier), SchemaSource::Editor, FieldValues::fromArray($values));
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
        $captures = new NoTypeCaptures($this->connection);

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

                // What broke is kept once the entities holding it are written.
                foreach ($aggregate->setAsideCaptures() as $holder => $records) {
                    foreach ($records as $recordId => $sites) {
                        $captures->keep($holder === '' ? $id : (string) $holder, (string) $recordId, $sites);
                    }
                }
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

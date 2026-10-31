<?php

namespace XyloIsCoding\CoconutCms\Persistence;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Boot\CodeStateCache;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationUnfinished;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\ConversionRegistry;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\BreaksNotAccepted;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EntriesFailToMove;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaEditingDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\SystemActor;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSave;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\EditorSaveReport;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\StaleSchemaRevision;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationBlocked;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationReport;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationResult;
use XyloIsCoding\CoconutCms\Persistence\Migration\MigrationSteps;
use XyloIsCoding\CoconutCms\Persistence\Migration\Migrator;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\MigrationPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\Policy\StrictPolicy;
use XyloIsCoding\CoconutCms\Persistence\Migration\StateKey;
use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Persistence\Platform\Platforms;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\SchemaCompiler;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityReader;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeRegistrations;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\EntitySchemaCodec;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaStore;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;

/**
 * The persistence layer, wired: gives sessions that load, save, delete and
 * query native entities as an actor, and migrates the database to the code.
 * Booting does no work; the first load, save, delete, query or status()
 * checks, once, that the database matches the code, and every one of them
 * throws PersistenceUnavailable while it doesn't.
 *
 * The check costs a few file stats and one query when a cache directory is
 * given: what reading the code found is remembered there until a watched
 * file changes. migrate() never trusts the cache.
 */
final class Persistence
{
    private readonly ValueObjectRegistry $valueObjects;
    private readonly EnumRegistry $enums;
    private readonly FieldValidatorRegistry $fieldValidators;
    private readonly PrototypeValidatorRegistry $prototypeValidators;
    private readonly SchemaStore $schemas;
    private readonly SchemaFieldValueStore $values;
    private readonly SchemaRegistry $registry;
    private readonly MigrationState $migrationState;
    private readonly CodeStateCache $cache;

    /**
     * The classes registered so far, by kind, null before any.
     * @var array{valueObjects: list<class-string>, enums: list<class-string>, fieldValidators: list<class-string>, prototypeValidators: list<class-string>}|null
     */
    private ?array $registered = null;

    /** Whether a check found the database matching the code: never checked again after that. */
    private bool $ready = false;

    /** Every module's entity class, registered on the first session. */
    private ?NativeEntityRegistry $entityClasses = null;

    /** Every module's converters and initializers, registered on first use. */
    private ?ConversionRegistry $conversions = null;

    /** @param list<Module> $modules Core first. */
    private function __construct(
        private readonly Connection $connection,
        private readonly array $modules,
        private readonly MigrateOn $migrateOn,
        private readonly MigrationPolicy $policy,
        ?string $cacheDirectory,
        private readonly ?string $schemaEditorRole,
    ) {
        $this->valueObjects = new ValueObjectRegistry();
        $this->enums = new EnumRegistry();
        $this->fieldValidators = new FieldValidatorRegistry();
        $this->prototypeValidators = new PrototypeValidatorRegistry();
        $this->schemas = new SchemaStore($connection, new EntitySchemaCodec($this->fieldValidators, $this->prototypeValidators, $this->valueObjects, $this->enums));
        $this->values = new SchemaFieldValueStore($connection);
        $this->registry = new SchemaRegistry($this->schemas);
        $this->migrationState = new MigrationState($connection);
        $this->cache = new CodeStateCache($cacheDirectory);
    }

    /**
     * Wires everything; touches neither the database nor any class.
     * @param list<Module> $modules The project's modules, in order: the core
     *        module goes first by itself.
     * @param string|null $cacheDirectory A writable directory to remember the
     *        code's state in; null reads every class on each first check.
     * @param MigrationPolicy $policy Strict unless given: a looser one is
     *        always a visible line of code.
     * @param string|null $schemaEditorRole The role an actor needs to save
     *        changes to editor-created types; null lets only the system do it.
     */
    public static function boot(
        Connection $connection,
        array $modules,
        ?string $cacheDirectory = null,
        MigrateOn $migrateOn = MigrateOn::Command,
        MigrationPolicy $policy = new StrictPolicy(),
        ?string $schemaEditorRole = null,
    ): self {
        $all = [new CoreModule(), ...$modules];
        $names = [];
        foreach ($all as $module) {
            if (preg_match('/^[a-z0-9-]+$/', $module->name()) !== 1) {
                throw new InvalidArgumentException(sprintf('Module %s is named "%s": only lowercase letters, digits and "-" are allowed.', $module::class, $module->name()));
            }

            if (isset($names[$module->name()])) {
                throw new InvalidArgumentException(sprintf('Two modules are named "%s": %s and %s.', $module->name(), $names[$module->name()], $module::class));
            }

            $names[$module->name()] = $module::class;
        }

        return new self($connection, $all, $migrateOn, $policy, $cacheDirectory, $schemaEditorRole);
    }

    /** @return list<Module> Every module booted, in order, the core module first. */
    public function modules(): array {
        return $this->modules;
    }

    /**
     * Whether entities can be read and written now. With MigrateOn::Request,
     * a pending migration runs here, and a blocked one throws.
     */
    public function status(): PersistenceStatus {
        if ($this->ready) {
            return PersistenceStatus::Ready;
        }

        Platforms::of($this->connection)->prepare($this->connection);
        ['stateKey' => $stored, 'startedAt' => $startedAt] = $this->migrationState->read();
        if ($startedAt !== null) {
            return PersistenceStatus::MigrationUnfinished;
        }

        // A database never migrated has nothing to compare with.
        if ($stored === null || $stored !== $this->codeState()['key']) {
            if ($this->migrateOn === MigrateOn::Command) {
                return PersistenceStatus::MigrationPending;
            }

            $this->migrate();
        }

        $this->ready = true;

        return PersistenceStatus::Ready;
    }

    /**
     * Entities as $actor sees them: every load, save, delete and query goes
     * through a session. SystemActor may read and write everything: for code
     * the site trusts, never for a request's user.
     */
    public function as(Actor $actor): PersistenceSession {
        return new PersistenceSession($this->connection, $this->registry, $this->values, $this->entityClasses(), $actor, $this->assertReady(...));
    }

    /**
     * Migrates the database to the code: what a deploy command calls. Reads
     * every class, never the cache, and remembers what it found for the
     * requests after it.
     */
    public function migrate(): MigrationResult {
        $blueprints = NativeEntityReader::readAll($this->allClasses());
        $classes = NativeRegistrations::classesOf($blueprints);
        $this->register($classes);

        $result = (new Migrator($this->connection, $this->registry, $this->schemas, $this->values, $this->policy))->migrate($blueprints, $this->steps());
        $this->remember(['key' => $result->stateKey, 'classes' => $classes]);
        $this->ready = true;

        return $result;
    }

    /**
     * What migrate() would do on this database, with this code and policy,
     * found without changing anything: run it against production before a
     * deploy. Reads every class, never the cache.
     */
    public function check(): MigrationReport {
        $blueprints = NativeEntityReader::readAll($this->allClasses());
        $this->register(NativeRegistrations::classesOf($blueprints));

        return (new Migrator($this->connection, $this->registry, $this->schemas, $this->values, $this->policy))->check($blueprints, $this->steps());
    }

    /**
     * Runs an editor save as $actor: changes the editor-created types as its
     * operations say, moving their content as a migration does, under this
     * policy. Reads every class, never the cache.
     * @throws SchemaEditingDenied When $actor doesn't hold the schema-editor role.
     * @throws StaleSchemaRevision|InvalidOperation|MigrationBlocked|BreaksNotAccepted|EntriesFailToMove As Migrator::saveEditor() says.
     */
    public function saveSchemas(EditorSave $save, Actor $actor): EditorSaveReport {
        $this->assertSchemaEditor($actor);
        $blueprints = NativeEntityReader::readAll($this->allClasses());
        $this->register(NativeRegistrations::classesOf($blueprints));

        return (new Migrator($this->connection, $this->registry, $this->schemas, $this->values, $this->policy))->saveEditor($blueprints, $this->steps(), $save);
    }

    /**
     * What saveSchemas() would do with $save, found without changing
     * anything. Takes the same right: it lists entries by id.
     */
    public function previewSchemas(EditorSave $save, Actor $actor): EditorSaveReport {
        $this->assertSchemaEditor($actor);
        $blueprints = NativeEntityReader::readAll($this->allClasses());
        $this->register(NativeRegistrations::classesOf($blueprints));

        return (new Migrator($this->connection, $this->registry, $this->schemas, $this->values, $this->policy))->previewEditor($blueprints, $this->steps(), $save);
    }

    /** Throws unless $actor holds the schema-editor role; with none named, unless it's the system. */
    private function assertSchemaEditor(Actor $actor): void {
        $allowed = $this->schemaEditorRole === null ? $actor instanceof SystemActor : $actor->hasRole($this->schemaEditorRole);
        if (!$allowed) {
            throw new SchemaEditingDenied();
        }
    }

    /**
     * Every converter and initializer the modules offer editor saves, by
     * identifier. Registers them, and the enums they may name, on first use.
     */
    public function conversions(): ConversionRegistry {
        if ($this->conversions === null) {
            $this->register(NativeRegistrations::classesOf(NativeEntityReader::readAll($this->allClasses())));
            $this->conversions = new ConversionRegistry($this->enums);
            foreach ($this->modules as $module) {
                foreach ($module->converters() as $class) {
                    $this->conversions->register($class);
                }
            }
        }

        return $this->conversions;
    }

    /** The enums the modules' classes use, by identifier: what a field the editor adds names one by. */
    public function enums(): EnumRegistry {
        $this->conversions();

        return $this->enums;
    }

    /** Throws unless status() says Ready. */
    private function assertReady(): void {
        match ($this->status()) {
            PersistenceStatus::Ready => null,
            PersistenceStatus::MigrationPending => throw new MigrationPending(),
            PersistenceStatus::MigrationUnfinished => throw new MigrationUnfinished((string) $this->migrationState->read()['startedAt']),
        };
    }

    /**
     * The code's state key and the classes to register: from the cache while
     * it's valid, read from every class otherwise. Registers the classes.
     * @return array{key: string, classes: array{valueObjects: list<class-string>, enums: list<class-string>, fieldValidators: list<class-string>, prototypeValidators: list<class-string>}}
     */
    private function codeState(): array {
        $state = $this->cache->read($this->moduleClasses());
        if ($state !== null) {
            $this->register($state['classes']);

            return $state;
        }

        $blueprints = NativeEntityReader::readAll($this->allClasses());
        $classes = NativeRegistrations::classesOf($blueprints);
        $this->register($classes);

        $batch = (new SchemaCompiler($this->registry, $this->values, $this->schemas))->compute($blueprints);
        $state = ['key' => StateKey::of($batch, $this->steps()), 'classes' => $classes];
        $this->remember($state);

        return $state;
    }

    /**
     * Writes $state to the cache, watching every file it came from: each
     * module's, each entity class's and its ancestors', and each registered
     * class's.
     * @param array{key: string, classes: array<string, list<class-string>>} $state
     */
    private function remember(array $state): void {
        $files = [];
        foreach ($this->modules as $module) {
            $files[] = (new ReflectionClass($module))->getFileName();
        }

        foreach ([...$this->allClasses(), ...array_merge(...array_values($state['classes']))] as $class) {
            for ($reflection = new ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
                $files[] = $reflection->getFileName();
            }
        }

        $this->cache->write($this->moduleClasses(), $state, array_values(array_filter($files, 'is_string')));
    }

    /**
     * Registers whatever in $classes isn't registered yet.
     * @param array{valueObjects: list<class-string>, enums: list<class-string>, fieldValidators: list<class-string>, prototypeValidators: list<class-string>} $classes
     */
    private function register(array $classes): void {
        $new = $classes;
        if ($this->registered !== null) {
            foreach ($classes as $kind => $list) {
                $new[$kind] = array_values(array_diff($list, $this->registered[$kind]));
                $this->registered[$kind] = [...$this->registered[$kind], ...$new[$kind]];
            }
        } else {
            $this->registered = $classes;
        }

        NativeRegistrations::registerAll($new, $this->valueObjects, $this->enums, $this->fieldValidators, $this->prototypeValidators);
    }

    /** Every module's steps, each recorded under its module's name, core's first. */
    private function steps(): MigrationSteps {
        $steps = MigrationSteps::none();
        foreach ($this->modules as $module) {
            foreach ($module->steps()->all() as $step) {
                $steps = $steps->add($module->name() . ':' . $step->id, $step->fixups);
            }
        }

        return $steps;
    }

    /** @return list<class-string> Every module's entity classes, core's first. */
    private function allClasses(): array {
        return array_merge(...array_map(static fn (Module $module): array => $module->classes(), $this->modules));
    }

    /** @return list<string> The modules' class names, in order: what the cache is valid for. */
    private function moduleClasses(): array {
        return array_map(static fn (Module $module): string => $module::class, $this->modules);
    }

    /** Every module's entity classes, registered once and shared by every session. */
    private function entityClasses(): NativeEntityRegistry {
        if ($this->entityClasses === null) {
            $this->entityClasses = new NativeEntityRegistry();
            foreach ($this->allClasses() as $class) {
                $this->entityClasses->register($class);
            }
        }

        return $this->entityClasses;
    }
}

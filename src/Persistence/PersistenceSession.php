<?php

namespace XyloIsCoding\CoconutCms\Persistence;

use Closure;
use InvalidArgumentException;
use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic\DynamicEntities;
use XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic\DynamicEntity;
use XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic\DynamicEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityValues;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\InvalidChange;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\StoredFormEditor;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\MigrationState;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Permission\TypeAction;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Entity\Record\EntityRecord;
use XyloIsCoding\CoconutCms\Persistence\Entity\Record\RecordReader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\HiddenValues;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityLoader;
use XyloIsCoding\CoconutCms\Persistence\Entity\Native\NativeEntityWriter;
use XyloIsCoding\CoconutCms\Persistence\Permission\Actor;
use XyloIsCoding\CoconutCms\Persistence\Permission\Permissions;
use XyloIsCoding\CoconutCms\Persistence\Query\Query;
use XyloIsCoding\CoconutCms\Persistence\Query\QueryRunner;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeViewClasses;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;

/**
 * Loads, saves, deletes and queries entities as one actor, as native
 * objects, or whole as DynamicEntitys: what
 * Persistence::as() gives. A field the actor may not read loads unset and
 * keeps its stored value on save; a save changing a field it may not write
 * throws PermissionDenied; a query through a field it may not read is
 * refused. Each session has its own objects: one per id, within it.
 */
final class PersistenceSession
{
    private readonly IdentityMap $identities;
    private readonly HiddenValues $hidden;
    private readonly NativeEntityLoader $loader;
    private readonly DynamicEntities $dynamic;
    private readonly NativeEntityWriter $writer;
    private readonly DynamicEntityWriter $dynamicWriter;
    private readonly QueryRunner $queries;
    private readonly RecordReader $records;
    private readonly SchemaLookup $schemas;
    private readonly Permissions $permissions;
    private readonly EntityReader $reader;
    private readonly StoredFormEditor $editor;
    private readonly MigrationState $migrationState;

    /**
     * Made by Persistence::as().
     * @param NativeEntityRegistry $classes Every native entity class.
     * @param Closure(): void $guard Throws while entities can't be read or written.
     */
    public function __construct(
        Connection $connection,
        SchemaRegistry $registry,
        SchemaFieldValueStore $values,
        NativeEntityRegistry $classes,
        Actor $actor,
        private readonly Closure $guard,
    ) {
        $this->schemas = $schemas = new SchemaLookup($registry);
        $this->migrationState = new MigrationState($connection);
        $this->permissions = $permissions = new Permissions($schemas, $actor);
        $this->identities = new IdentityMap();
        $this->hidden = new HiddenValues();
        $this->reader = $reader = new EntityReader($connection, $registry, $values);
        $views = new NativeViewClasses($classes, $schemas);
        $this->loader = new NativeEntityLoader($reader, $views, $this->identities, $permissions, $this->hidden);
        $resolver = new FieldValueResolver($registry, $values);
        $this->dynamic = new DynamicEntities($reader, $this->loader, $this->identities, $schemas, $views, $permissions, $resolver);
        $this->records = new RecordReader($reader, $schemas, $permissions, $resolver);
        $entityWriter = EntityWriter::of($connection, $registry, $permissions, $values);
        $this->writer = new NativeEntityWriter($entityWriter, $registry, $values, $this->identities, $this->hidden);
        $this->dynamicWriter = new DynamicEntityWriter($entityWriter, new NativeEntityValues($registry, $values, $this->identities), $this->dynamic, $this->identities, $schemas, $resolver, $this->hidden);
        $this->queries = new QueryRunner($connection, $schemas, $permissions, $this->identities, $this->load(...), $guard);
        $this->editor = new StoredFormEditor($schemas, $this->dynamic, $this->referenced(...));
    }

    /**
     * Creates a $type entity of any type from its type's defaults, $changes
     * applied by path in stored form, as StoredFormEditor describes, then
     * saved: for code knowing no class. Its setters and afterEdit() run as
     * for any edit.
     * @param array<string, mixed> $changes By path.
     * @return string Its id.
     * @throws InvalidChange For an unknown type, or a change it can't apply.
     * @throws PermissionDenied When the actor may not create a $type.
     * @throws ValidationFailed When a setter, afterEdit() or a validator refuses it.
     */
    public function create(string $type, array $changes): string {
        ($this->guard)();
        if (!$this->schemas->has($type)) {
            throw new InvalidChange('', sprintf('There is no type "%s".', $type));
        }

        if (!$this->permissions->may($type, TypeAction::Create)) {
            throw PermissionDenied::ofType($type, TypeAction::Create);
        }

        return $this->dynamicWriter->save($this->editor->create($type, $changes));
    }

    /**
     * Loads the standalone entity stored as $id, of any type, applies
     * $changes by path in stored form, as StoredFormEditor describes, and
     * saves it: for code knowing no class. False when there's no such entity,
     * or it's owned: an owned one is changed through its owner's paths.
     * @param array<string, mixed> $changes By path.
     * @throws InvalidChange For a change it can't apply.
     * @throws PermissionDenied When the actor may not read or update it, or write a field changed.
     * @throws ValidationFailed When a setter, afterEdit() or a validator refuses it.
     */
    public function update(string $id, array $changes): bool {
        ($this->guard)();
        $type = $this->standaloneType($id);
        if ($type === null) {
            return false;
        }

        if (!$this->permissions->may($type, TypeAction::Update)) {
            throw PermissionDenied::ofType($type, TypeAction::Update);
        }

        $entity = $this->dynamic->load($id);
        $this->editor->edit($entity, $changes);
        $this->dynamicWriter->save($entity);

        return true;
    }

    /**
     * Deletes the standalone entity stored as $id, of any type, with
     * everything it owns: for code knowing no class. False when there's no
     * such entity, or it's owned.
     * @throws PermissionDenied When the actor may not read or delete it.
     */
    public function deleteById(string $id): bool {
        ($this->guard)();
        if ($this->standaloneType($id) === null) {
            return false;
        }

        $this->dynamicWriter->delete($this->dynamic->load($id));

        return true;
    }

    /** The type of the standalone entity stored as $id; null when there's none, or it's owned. */
    private function standaloneType(string $id): ?string {
        if ($this->reader->isOwned($id) !== false) {
            return null;
        }

        return $this->reader->identifiersOf([$id])[$id] ?? null;
    }

    /**
     * The standalone entity an edit sets a Reference to, as this actor
     * loads it, of any type. Throws InvalidChange naming $at when there's none.
     */
    private function referenced(string $id, string $at): DynamicEntity {
        $owned = $this->reader->isOwned($id);
        if ($owned === null) {
            throw new InvalidChange($at, sprintf('"%s" points at %s: there is no such entity.', $at, $id));
        }

        if ($owned) {
            throw new InvalidChange($at, sprintf('"%s" points at %s, an owned entity: an edit points a Reference at a standalone one only.', $at, $id));
        }

        return $this->dynamic->load($id);
    }

    /**
     * The entity stored as $id, null when there's none. An editor-created
     * entity loads as its last native class, its native view; one with no
     * native ancestor can't load here: loadDynamic() it.
     */
    public function load(string $id): ?object {
        ($this->guard)();

        return $this->loader->load($id);
    }

    /**
     * The standalone entity stored as $id, whole, whatever its type, as a
     * DynamicEntity; null when there's none. One per entity, wrapping the
     * object load() gives, when its type has a native ancestor.
     * @throws PermissionDenied When the actor may not read its type.
     */
    public function loadDynamic(string $id): ?DynamicEntity {
        ($this->guard)();

        return $this->dynamic->load($id);
    }

    /**
     * A new entity of $type, any type, native or editor-created, as a
     * DynamicEntity: the same as `new Car()`. It has no id until its first
     * save. Its values are its type's defaults, resolved on its real type,
     * its native view included.
     * @throws InvalidArgumentException For a type there's no schema for.
     */
    public function newDynamic(string $type): DynamicEntity {
        ($this->guard)();

        return $this->dynamic->create($type);
    }

    /**
     * Stores $entity with everything it owns: a native object, or a
     * DynamicEntity, saved as its real type, afterEdit() first on what set()
     * changed in it.
     * @return string Its id.
     */
    public function save(object $entity): string {
        ($this->guard)();

        return $entity instanceof DynamicEntity ? $this->dynamicWriter->save($entity) : $this->writer->save($entity);
    }

    /** Deletes $entity, a native object or a DynamicEntity, with everything it owns. */
    public function delete(object $entity): void {
        ($this->guard)();
        if ($entity instanceof DynamicEntity) {
            $this->dynamicWriter->delete($entity);
        } else {
            $this->writer->delete($entity);
        }
    }

    /**
     * A query over $class's standalone entities, its subclasses' included.
     * Nothing runs until page(), ids() or count(), which throw
     * PersistenceUnavailable the way load() does.
     * @param class-string $class A native entity class.
     */
    public function query(string $class): Query {
        return $this->queryType(NativeIdentifiers::of($class));
    }

    /**
     * A query over the standalone entities of type $identifier, native or
     * editor-created, its subtypes' included: for code knowing no class.
     * Use idPage() and read() on what it finds; page() builds native objects.
     */
    public function queryType(string $identifier): Query {
        return new Query($this->queries, $identifier);
    }

    /**
     * The standalone entity stored as $id as plain values, native or
     * editor-created, no object built: null when there's none, or it's
     * owned. Throws PermissionDenied for a type the actor may not read; a
     * field it may not read is left out.
     */
    public function read(string $id): ?EntityRecord {
        ($this->guard)();

        return $this->records->read($id);
    }

    /**
     * What a new `$type` entity holds, as plain values: its type's defaults,
     * in the form read() gives, with no id. A field the actor may not read
     * is left out.
     */
    public function defaultsOf(string $type): EntityRecord {
        ($this->guard)();

        return $this->records->defaultsOf($type);
    }

    /** Every type's schema, as the database holds it now: what reads and writes follow. */
    public function schemas(): SchemaLookup {
        ($this->guard)();

        return $this->schemas;
    }

    /**
     * The schema revision the types are at: raised by every migration that
     * changes stored schemas, native or editor. An editor save sends the one
     * it was built on.
     */
    public function schemaRevision(): int {
        ($this->guard)();

        return $this->migrationState->revision();
    }

    /** What the actor may do, type by type and field by field. */
    public function permissions(): Permissions {
        return $this->permissions;
    }
}

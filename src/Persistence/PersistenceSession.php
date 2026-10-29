<?php

namespace XyloIsCoding\CoconutCms\Persistence;

use Closure;
use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\InvalidChange;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\NativeEditor;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
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
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver\FieldValueResolver;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\SchemaFieldValueStore;

/**
 * Loads, saves, deletes and queries native entities as one actor: what
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
    private readonly NativeEntityWriter $writer;
    private readonly QueryRunner $queries;
    private readonly RecordReader $records;
    private readonly SchemaLookup $schemas;
    private readonly Permissions $permissions;
    private readonly EntityReader $reader;
    private readonly NativeEditor $editor;
    private readonly NativeEntityRegistry $classes;

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
        $this->permissions = $permissions = new Permissions($schemas, $actor);
        $this->identities = new IdentityMap();
        $this->hidden = new HiddenValues();
        $this->reader = $reader = new EntityReader($connection, $registry, $values);
        $this->loader = new NativeEntityLoader($reader, $classes, $this->identities, $permissions, $this->hidden);
        $this->records = new RecordReader($reader, $schemas, $permissions, new FieldValueResolver($registry, $values));
        $this->writer = new NativeEntityWriter(EntityWriter::of($connection, $registry, $permissions, $values), $registry, $values, $this->identities, $this->hidden);
        $this->queries = new QueryRunner($connection, $schemas, $permissions, $this->identities, $this->load(...), $guard);
        $this->editor = new NativeEditor($schemas, $permissions, $classes, $this->referenced(...), $this->identities->idOf(...));
        $this->classes = $classes;
    }

    /**
     * Creates a $type entity from its type's defaults, $changes applied by
     * path in stored form, as NativeEditor describes, then saved: for code
     * knowing no class. Its setters and afterEdit() run as for any edit.
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

        return $this->writer->save($this->editor->create($type, $changes));
    }

    /**
     * Loads the standalone entity stored as $id, applies $changes by path in
     * stored form, as NativeEditor describes, and saves it: for code knowing
     * no class. False when there's no such entity, or it's owned: an owned
     * one is changed through its owner's paths.
     * @param array<string, mixed> $changes By path.
     * @throws InvalidChange For an editor-created entity, or a change it can't apply.
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

        $entity = $this->loader->load($id);
        $this->editor->edit($entity, $changes);
        $this->writer->save($entity);

        return true;
    }

    /**
     * Deletes the standalone entity stored as $id with everything it owns:
     * for code knowing no class. False when there's no such entity, or it's
     * owned.
     * @throws InvalidChange For an editor-created entity.
     * @throws PermissionDenied When the actor may not read or delete it.
     */
    public function deleteById(string $id): bool {
        ($this->guard)();
        if ($this->standaloneType($id) === null) {
            return false;
        }

        $this->writer->delete($this->loader->load($id));

        return true;
    }

    /**
     * The type of the standalone native entity stored as $id; null when
     * there's none, or it's owned. Throws InvalidChange for an
     * editor-created one: editor saves, not built yet, change those.
     */
    private function standaloneType(string $id): ?string {
        if ($this->reader->isOwned($id) !== false) {
            return null;
        }

        $type = $this->reader->identifiersOf([$id])[$id] ?? null;
        if ($type !== null && !$this->classes->has($type)) {
            throw new InvalidChange('', sprintf('Entity %s is a %s, an editor-created type: editor saves, not built yet, change its entities.', $id, $type));
        }

        return $type;
    }

    /**
     * The standalone entity an edit sets a Reference to, as this actor
     * loads it. Throws InvalidChange naming $at when there's none.
     */
    private function referenced(string $id, string $at): object {
        $owned = $this->reader->isOwned($id);
        if ($owned === null) {
            throw new InvalidChange($at, sprintf('"%s" points at %s: there is no such entity.', $at, $id));
        }

        if ($owned) {
            throw new InvalidChange($at, sprintf('"%s" points at %s, an owned entity: an edit points a Reference at a standalone one only.', $at, $id));
        }

        return $this->loader->load($id);
    }

    /** The entity stored as $id, null when there's none. */
    public function load(string $id): ?object {
        ($this->guard)();

        return $this->loader->load($id);
    }

    /** Stores $entity with everything it owns. @return string Its id. */
    public function save(object $entity): string {
        ($this->guard)();

        return $this->writer->save($entity);
    }

    /** Deletes $entity with everything it owns. */
    public function delete(object $entity): void {
        ($this->guard)();
        $this->writer->delete($entity);
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

    /** What the actor may do, type by type and field by field. */
    public function permissions(): Permissions {
        return $this->permissions;
    }
}

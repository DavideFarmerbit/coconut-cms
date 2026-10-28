<?php

namespace XyloIsCoding\CoconutCms\Persistence;

use Closure;
use Doctrine\DBAL\Connection;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityReader;
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
        $reader = new EntityReader($connection, $registry, $values);
        $this->loader = new NativeEntityLoader($reader, $classes, $this->identities, $permissions, $this->hidden);
        $this->records = new RecordReader($reader, $schemas, $permissions);
        $this->writer = new NativeEntityWriter(EntityWriter::of($connection, $registry, $permissions, $values), $registry, $values, $this->identities, $this->hidden);
        $this->queries = new QueryRunner($connection, $schemas, $permissions, $this->identities, $this->load(...), $guard);
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

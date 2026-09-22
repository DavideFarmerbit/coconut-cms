<?php

namespace XyloIsCoding\CoconutCms\Storage;

use Doctrine\DBAL\Connection;

/**
 * Hands out one Repository per entity class, sharing one Connection and one
 * IdentityMap across all of them. A Repository asks this to resolve a reference or
 * collection field into the referenced class's own Repository, so a Tag reached from a
 * Product goes through the exact same identity-mapped lookup a direct Tag query would.
 */
final class EntityManager
{
    private readonly IdentityMap $identityMap;

    /** @var array<class-string, Repository> */
    private array $repositories = [];

    /**
     * @param array<class-string, string> $tables entity class => table name, every
     *   level of an inheritance chain needs its own entry, not just the leaf class
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly array $tables,
    ) {
        $this->identityMap = new IdentityMap();
    }

    /** @param class-string $class */
    public function repository(string $class): Repository
    {
        return $this->repositories[$class] ??= new Repository($this->connection, $this->identityMap, $this, $class, $this->tables);
    }
}

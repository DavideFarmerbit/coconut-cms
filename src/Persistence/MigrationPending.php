<?php

namespace XyloIsCoding\CoconutCms\Persistence;

/**
 * Thrown by a read or a write while the code is ahead of the database: its
 * types or steps changed, and no migration has run for them yet.
 */
final class MigrationPending extends PersistenceUnavailable
{
    public function __construct() {
        parent::__construct('The code changed since the database was last migrated: entities can\'t be read or written until a migration runs.');
    }
}

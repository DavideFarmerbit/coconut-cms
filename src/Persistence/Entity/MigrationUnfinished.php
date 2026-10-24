<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use XyloIsCoding\CoconutCms\Persistence\PersistenceUnavailable;

/**
 * Thrown by a read or a write while a migration is reshaping the tables, or
 * after one crashed. Running the migration again finishes it.
 */
final class MigrationUnfinished extends PersistenceUnavailable
{
    /** @param string $startedAt When the migration started, UTC, as stored. */
    public function __construct(string $startedAt) {
        parent::__construct(sprintf('A migration started at %s UTC has not finished: entities can\'t be read or written until it does.', $startedAt));
    }
}

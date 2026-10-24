<?php

namespace XyloIsCoding\CoconutCms\Persistence;

/** Whether entities can be read and written right now, and if not, why. */
enum PersistenceStatus
{
    /** The database matches the code. */
    case Ready;

    /** The code changed, and no migration has run for it yet. */
    case MigrationPending;

    /** A migration is running, or one crashed: running one again finishes it. */
    case MigrationUnfinished;

    public function isReady(): bool {
        return $this === self::Ready;
    }
}

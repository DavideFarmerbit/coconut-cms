<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use RuntimeException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;

/**
 * Differences between the stored schemas and the code that no declared fixup
 * explains and the policy won't resolve. Nothing was changed: the migration
 * stops before touching the database, and stays stopped until they're
 * declared.
 */
final class MigrationBlocked extends RuntimeException
{
    /** @param list<Difference> $differences */
    public function __construct(
        public readonly array $differences,
    ) {
        parent::__construct(sprintf(
            "The migration is blocked: these differences are declared by no fixup.\n- %s",
            implode("\n- ", array_map(static fn (Difference $difference): string => $difference->describe(), $differences)),
        ));
    }
}

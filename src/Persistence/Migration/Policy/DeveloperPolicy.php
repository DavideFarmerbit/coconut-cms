<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Policy;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\ParentChanged;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\TableChanged;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroupRemoved;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\DropUniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\MoveTable;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Reparent;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;

/**
 * For development: every change that can't lose stored data passes
 * undeclared. On top of strict's additions: a table moved, a unique group
 * dropped, and a parent changed only by inserting levels. Anything that can
 * lose data still blocks.
 */
final readonly class DeveloperPolicy implements MigrationPolicy
{
    private StrictPolicy $strict;

    public function __construct() {
        $this->strict = new StrictPolicy();
    }

    public function fixupFor(Difference $difference, SchemaState $schemas): ?Fixup {
        return $this->strict->fixupFor($difference, $schemas) ?? match (true) {
            $difference instanceof TableChanged => new MoveTable($difference->identifier()),
            $difference instanceof UniqueGroupRemoved => new DropUniqueGroup($difference->identifier(), ...array_map('strval', $difference->group->fields())),
            $difference instanceof ParentChanged && self::onlyInsertsLevels($difference->identifier(), $schemas) => new Reparent($difference->identifier()),
            default => null,
        };
    }

    /**
     * Whether $identifier's chain in code holds its whole stored chain, in
     * order: every level it had, it keeps.
     */
    private static function onlyInsertsLevels(string $identifier, SchemaState $schemas): bool {
        $new = self::ancestors($identifier, $schemas->codeSchemas());
        $position = 0;
        foreach (self::ancestors($identifier, $schemas->native()) as $level) {
            $found = array_search($level, array_slice($new, $position), true);
            if ($found === false) {
                return false;
            }

            $position += $found + 1;
        }

        return true;
    }

    /**
     * $identifier's ancestors in $schemas, nearest first.
     * @param array<string, EntitySchema> $schemas
     * @return list<string>
     */
    private static function ancestors(string $identifier, array $schemas): array {
        $ancestors = [];
        for ($parent = $schemas[$identifier]->parent ?? null; $parent !== null; $parent = $schemas[$parent]->parent ?? null) {
            $ancestors[] = $parent;
        }

        return $ancestors;
    }
}

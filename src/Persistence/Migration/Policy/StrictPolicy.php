<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Policy;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\FieldAdded;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\TypeAdded;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroupAdded;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\AddType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\AddUniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * The default policy: only an addition passes undeclared, a new type, field
 * or unique group. Everything else blocks until it's declared.
 */
final readonly class StrictPolicy implements MigrationPolicy
{
    public function fixupFor(Difference $difference, SchemaState $schemas): ?Fixup {
        return match (true) {
            $difference instanceof TypeAdded => new AddType($difference->identifier()),
            $difference instanceof FieldAdded => new AddField($difference->identifier(), (string) $difference->name),
            $difference instanceof UniqueGroupAdded => new AddUniqueGroup($difference->identifier(), ...array_map('strval', $difference->group->fields())),
            default => null,
        };
    }
}

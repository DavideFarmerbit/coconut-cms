<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Policy;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\Difference;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\FieldChanged;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\FieldRemoved;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\ParentChanged;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\TypeRemoved;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\DropField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\DropType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Reparent;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * Everything passes, best effort, losing whatever it must: a removal is
 * dropped, a changed field reset, any parent change applied. An undeclared
 * rename is a drop and an add. Never for a database whose content matters.
 */
final readonly class UnsafePolicy implements MigrationPolicy
{
    private DeveloperPolicy $developer;

    public function __construct() {
        $this->developer = new DeveloperPolicy();
    }

    public function fixupFor(Difference $difference, SchemaState $schemas): ?Fixup {
        return $this->developer->fixupFor($difference, $schemas) ?? match (true) {
            $difference instanceof TypeRemoved => new DropType($difference->identifier()),
            $difference instanceof FieldRemoved => new DropField($difference->identifier(), (string) $difference->name),
            $difference instanceof FieldChanged => RetypeField::reset($difference->identifier(), (string) $difference->name),
            $difference instanceof ParentChanged => new Reparent($difference->identifier()),
            default => null,
        };
    }
}

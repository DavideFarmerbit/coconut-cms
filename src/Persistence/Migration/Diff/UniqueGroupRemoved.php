<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;

/** A unique group the stored schema declares on a type that the code no longer does. */
final readonly class UniqueGroupRemoved implements Difference
{
    public function __construct(
        private string $identifier,
        public UniqueGroup $group,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function describe(): string {
        return sprintf('Type "%s" lost its unique group over %s.', $this->identifier, UniqueGroups::describe($this->group));
    }
}

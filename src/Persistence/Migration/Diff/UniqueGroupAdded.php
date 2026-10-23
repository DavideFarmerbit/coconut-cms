<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;

/** A unique group the code declares on a type that the stored schema doesn't. */
final readonly class UniqueGroupAdded implements Difference
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
        return sprintf('Type "%s" gained a unique group over %s.', $this->identifier, UniqueGroups::describe($this->group));
    }
}

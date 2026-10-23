<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

/** A type's own level moved to another table. */
final readonly class TableChanged implements Difference
{
    public function __construct(
        private string $identifier,
        public string $old,
        public string $new,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function describe(): string {
        return sprintf('Type "%s" moved its table from "%s" to "%s".', $this->identifier, $this->old, $this->new);
    }
}

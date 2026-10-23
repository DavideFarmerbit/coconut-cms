<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

/** A stored native type the code no longer has. */
final readonly class TypeRemoved implements Difference
{
    public function __construct(
        private string $identifier,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function describe(): string {
        return sprintf('Type "%s" was removed.', $this->identifier);
    }
}

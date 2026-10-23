<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

/** A type in code that nothing stored has yet. */
final readonly class TypeAdded implements Difference
{
    public function __construct(
        private string $identifier,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function describe(): string {
        return sprintf('Type "%s" was added.', $this->identifier);
    }
}

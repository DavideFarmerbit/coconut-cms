<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * A type removed with its content: its entities and everything they own are
 * deleted, and References to them become null.
 */
final readonly class DropType implements Fixup
{
    public function __construct(
        public string $type,
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        return $schemas->without($this->type);
    }

    public function describe(): string {
        return sprintf('DropType(%s)', $this->type);
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/** A new type, as its target declares it: the code, or an editor save. Its tables start empty. */
final readonly class AddType implements Fixup
{
    public function __construct(
        public string $type,
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        return $schemas->withTargetType($this->type);
    }

    public function describe(): string {
        return sprintf('AddType(%s)', $this->type);
    }
}

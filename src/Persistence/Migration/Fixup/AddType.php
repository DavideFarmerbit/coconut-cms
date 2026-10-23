<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/** A new type, as the code declares it. Its tables start empty. */
final readonly class AddType implements Fixup
{
    public function __construct(
        public string $type,
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        return $schemas->withCodeType($this->type);
    }

    public function describe(): string {
        return sprintf('AddType(%s)', $this->type);
    }
}

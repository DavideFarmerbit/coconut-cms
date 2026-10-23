<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/** A type's own level moved to the table the code gives it, its content kept. */
final readonly class MoveTable implements Fixup
{
    public function __construct(
        public string $type,
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        return $schemas->with($schemas->get($this->type)->withTable($schemas->code($this->type)->table));
    }

    public function describe(): string {
        return sprintf('MoveTable(%s)', $this->type);
    }
}

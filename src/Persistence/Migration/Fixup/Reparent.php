<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * A type moved under the parent the code gives it. A level it gains follows
 * its defaults; a level it loses takes its data with it.
 */
final readonly class Reparent implements Fixup
{
    public function __construct(
        public string $type,
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        return $schemas->with($schemas->get($this->type)->withParent($schemas->code($this->type)->parent));
    }

    public function describe(): string {
        return sprintf('Reparent(%s)', $this->type);
    }
}

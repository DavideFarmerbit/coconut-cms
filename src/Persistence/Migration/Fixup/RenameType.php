<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;

/**
 * A type renamed, its content kept. Every parent and target naming it follows,
 * in schemas of every source, and so does its table: it takes the one the
 * code gives the renamed type.
 */
final readonly class RenameType implements Fixup
{
    public function __construct(
        public string $old,
        public string $new,
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $renamed = $schemas->get($this->old)
            ->withIdentifier($this->new)
            ->withTable($schemas->code($this->new)->table);

        return $schemas->renamed($this->old, $renamed)->map(fn (EntitySchema $schema): EntitySchema => $schema
            ->withParent($schema->parent === $this->old ? $this->new : $schema->parent)
            ->withFields($schema->fields->retargeted($this->old, $this->new)));
    }

    public function describe(): string {
        return sprintf('RenameType(%s → %s)', $this->old, $this->new);
    }
}

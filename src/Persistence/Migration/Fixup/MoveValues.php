<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Values moved between two paths of a type's aggregate: across levels, into
 * or out of an Embed, across an owned boundary. Data only: it changes no
 * schema, so whatever the move goes with, a field dropped or added, is
 * declared next to it.
 */
final readonly class MoveValues implements Fixup
{
    public FieldPath $from;
    public FieldPath $to;

    public function __construct(
        public string $type,
        string $from,
        string $to,
    ) {
        $this->from = FieldPath::fromString($from);
        $this->to = FieldPath::fromString($to);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schemas->get($this->type);

        return $schemas;
    }

    public function describe(): string {
        return sprintf('MoveValues(%s, %s → %s)', $this->type, $this->from, $this->to);
    }
}

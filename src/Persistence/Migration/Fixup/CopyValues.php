<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Values copied between two paths of a type's aggregate, wherever the type
 * lives: `title` filled from `name`, across levels, into or out of an Embed,
 * across an owned boundary. `from` keeps its values; whatever was at `to` is
 * replaced. Each copy is pinned, a value following `from`'s default
 * included, and compared with `to`'s default like a converter's output. An
 * owned object copied is another one, with new ids: References keep pointing
 * at the one left at `from`. To keep an owned object's identity, move it
 * with MoveValues instead. Data only, like MoveValues: both paths must exist
 * when it runs. A type's own defaults never change.
 */
final readonly class CopyValues implements Fixup, MovesValues
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

    /** Changes no schema; both paths must exist at this point. */
    public function applyTo(SchemaState $schemas): SchemaState {
        $schemas->assertPath($this->type, $this->from);
        $schemas->assertPath($this->type, $this->to);

        return $schemas;
    }

    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        foreach (Occurrences::ofType($this->type, $values, $before) as $occurrence) {
            $values->copy($occurrence->pathOf($this->from), $occurrence->pathOf($this->to));
        }
    }

    public function movedTypes(): array {
        return [$this->type];
    }

    public function describe(): string {
        return sprintf('CopyValues(%s, %s → %s)', $this->type, $this->from, $this->to);
    }
}

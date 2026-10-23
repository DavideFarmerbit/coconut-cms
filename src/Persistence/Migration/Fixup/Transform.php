<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\MigratingValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Transformation;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * What no shorthand covers, run at every place its type lives over the values
 * rooted there. On the schemas, it amounts to the fixups its transformation
 * declares; only their schema side counts, the transformation itself moves
 * the values.
 */
final readonly class Transform implements Fixup, MovesValues
{
    public function __construct(
        public string $type,
        private Transformation $transformation,
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schemas->get($this->type);
        foreach ($this->transformation->declares() as $fixup) {
            $schemas = $fixup->applyTo($schemas);
        }

        return $schemas;
    }

    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        foreach (Occurrences::ofType($this->type, $values, $before) as $occurrence) {
            $this->transformation->transform(new MigratingValues($values, $occurrence->path, $occurrence->join));
        }
    }

    public function describe(): string {
        return sprintf('Transform(%s, %s)', $this->type, $this->transformation::class);
    }
}

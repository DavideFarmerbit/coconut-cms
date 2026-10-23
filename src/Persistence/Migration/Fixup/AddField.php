<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * A field added to a type, as the code declares it. Without an initializer,
 * every existing entity follows its default; with one, existing entities get
 * what it decides, wherever the type lives.
 */
final readonly class AddField implements Fixup, MovesValues
{
    public FieldName $field;

    public function __construct(
        public string $type,
        string $field,
        private ?FieldInitializer $initializer = null,
    ) {
        $this->field = FieldName::of($field);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schemas->assertNoField($this->type, $this->field);
        $schema = $schemas->get($this->type);

        return $schemas->with($schema->withFields($schema->fields->with($this->field, $schemas->codeField($this->type, $this->field))));
    }

    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        if ($this->initializer === null) {
            return;
        }

        $field = $after->field($this->type, $this->field);
        foreach (Occurrences::ofType($this->type, $values, $before) as $occurrence) {
            $values->write($occurrence->fieldPath($this->field), $field, $this->initializer->initialize($values->valuesAt($occurrence->path)));
        }
    }

    public function describe(): string {
        return $this->initializer === null
            ? sprintf('AddField(%s, %s)', $this->type, $this->field)
            : sprintf('AddField(%s, %s, %s)', $this->type, $this->field, $this->initializer::class);
    }
}

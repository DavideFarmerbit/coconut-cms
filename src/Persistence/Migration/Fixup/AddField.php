<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * A field added to a type, as the code declares it. Without a fixup, every
 * existing entity follows its default; declared, existing entities can be
 * given something else.
 */
final readonly class AddField implements Fixup
{
    public FieldName $field;

    public function __construct(
        public string $type,
        string $field,
    ) {
        $this->field = FieldName::of($field);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schemas->assertNoField($this->type, $this->field);
        $schema = $schemas->get($this->type);

        return $schemas->with($schema->withFields($schema->fields->with($this->field, $schemas->codeField($this->type, $this->field))));
    }

    public function describe(): string {
        return sprintf('AddField(%s, %s)', $this->type, $this->field);
    }
}

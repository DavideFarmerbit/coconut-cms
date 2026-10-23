<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * A type's own field changing what it holds: its kind, target, scalar type,
 * enum, or item. reset() drops every value and lets every entity follow the
 * new default. Converting values instead comes with the data side.
 */
final readonly class RetypeField implements Fixup
{
    public FieldName $field;

    private function __construct(
        public string $type,
        string $field,
    ) {
        $this->field = FieldName::of($field);
    }

    /** Every value dropped: every entity follows the field's new default. */
    public static function reset(string $type, string $field): self {
        return new self($type, $field);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $schemas->field($this->type, $this->field);
        $schema = $schemas->get($this->type);

        return $schemas->with($schema->withFields($schema->fields->with($this->field, $schemas->codeField($this->type, $this->field))));
    }

    public function describe(): string {
        return sprintf('RetypeField::reset(%s, %s)', $this->type, $this->field);
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Breaks;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/** A field an editor-created type declares has the name of one it now inherits: the inherited one keeps it. */
final readonly class FieldNameClash implements EditorBreak
{
    /** @param string $ancestor The ancestor declaring the field it now inherits. */
    public function __construct(
        private string $identifier,
        public FieldName $field,
        public string $ancestor,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function key(): string {
        return sprintf('name-clash|%s|%s', $this->identifier, $this->field);
    }

    public function describe(): string {
        return sprintf('Field "%s" of editor-created type "%s" has the name of a field it now inherits from "%s".', $this->field, $this->identifier, $this->ancestor);
    }
}

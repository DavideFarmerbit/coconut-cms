<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Breaks;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/** A field of an editor-created type declares a target, or item target, that's gone. */
final readonly class FieldTargetGone implements EditorBreak
{
    /** @param string $target The type it names that's gone. */
    public function __construct(
        private string $identifier,
        public FieldName $field,
        public string $target,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function key(): string {
        return sprintf('target-gone|%s|%s', $this->identifier, $this->field);
    }

    public function describe(): string {
        return sprintf('Field "%s" of editor-created type "%s" points at type "%s", which is gone.', $this->field, $this->identifier, $this->target);
    }
}

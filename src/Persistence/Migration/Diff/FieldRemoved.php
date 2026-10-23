<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/** A field the stored schema declares on a type that the code no longer does. */
final readonly class FieldRemoved implements Difference
{
    /** @param FieldDescriptor $field What it was: how its stored values are read. */
    public function __construct(
        private string $identifier,
        public FieldName $name,
        public FieldDescriptor $field,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function describe(): string {
        return sprintf('Field "%s" was removed from type "%s".', $this->name, $this->identifier);
    }
}

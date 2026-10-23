<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/** A field the code declares on a type that the stored schema doesn't. */
final readonly class FieldAdded implements Difference
{
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
        return sprintf('Field "%s" was added to type "%s".', $this->name, $this->identifier);
    }
}

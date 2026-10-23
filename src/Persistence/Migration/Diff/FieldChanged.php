<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * A field declared both in the stored schema and in code, but differently:
 * its kind, target, scalar type, enum, item, or how it's indexed.
 */
final readonly class FieldChanged implements Difference
{
    public function __construct(
        private string $identifier,
        public FieldName $name,
        public FieldDescriptor $old,
        public FieldDescriptor $new,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function describe(): string {
        return sprintf('Field "%s" of type "%s" changed what it holds.', $this->name, $this->identifier);
    }
}

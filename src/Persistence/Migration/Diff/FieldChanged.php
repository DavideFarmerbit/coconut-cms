<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;

/**
 * A field declared both in the stored schema and in code, but differently:
 * its kind, target, scalar type, enum, item, how it's indexed, or it can no
 * longer hold null.
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
        if ($this->onlyNarrows()) {
            return sprintf('Field "%s" of type "%s" can no longer hold null.', $this->name, $this->identifier);
        }

        return sprintf('Field "%s" of type "%s" changed what it holds.', $this->name, $this->identifier);
    }

    /** Whether the only change is that the field, or its items, can no longer hold null. */
    public function onlyNarrows(): bool {
        return $this->old->ownOrItemNullability() === Nullability::Nullable
            && $this->new->ownOrItemNullability() === Nullability::NotNullable
            && $this->old->differsOnlyInNullability($this->new);
    }
}

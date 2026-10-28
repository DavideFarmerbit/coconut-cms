<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\NoType;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;

/**
 * A field of an editor-created type that broke, set aside: what it was, and
 * every entity's values for it, captured in the side table under its id.
 */
final readonly class BrokenField implements NoTypeRecord
{
    /**
     * @param string $name Its name when it broke.
     * @param FieldDescriptor $descriptor What it was when it broke: what its captured values are.
     * @param string $origin The type that declared it: the ancestor cut off,
     *        or the type holding the record.
     */
    public function __construct(
        public string $id,
        public string $name,
        public FieldDescriptor $descriptor,
        public string $origin,
        public BrokenFieldReason $reason,
    ) {
    }

    public function id(): string {
        return $this->id;
    }
}

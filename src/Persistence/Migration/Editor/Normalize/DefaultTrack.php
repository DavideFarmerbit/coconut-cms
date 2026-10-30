<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Normalize;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;

/**
 * The last default change a save makes at one path of one type, followed
 * through renames: the type, the field the path starts with, and the rest
 * of the path. What OperationNormalizer keeps of every SetDefault and
 * InheritDefault.
 */
final class DefaultTrack
{
    /**
     * @param FieldTrack|string $field The field the path starts with: an
     *        editor-created type's, followed, or a native type's, by name.
     * @param string $rest The path after that field, its join included: "", ":power", ".0.size".
     * @param DefaultValue|null $value What it pins; null when it inherits.
     */
    public function __construct(
        public readonly TypeTrack $type,
        public readonly FieldTrack|string $field,
        public readonly string $rest,
        public ?DefaultValue $value,
    ) {
    }

    /** The field's name now. */
    public function fieldName(): string {
        return $this->field instanceof FieldTrack ? $this->field->name : $this->field;
    }

    /** Whether the type or the field is gone at the end of the save. */
    public function isGone(): bool {
        return $this->type->deleted || ($this->field instanceof FieldTrack && $this->field->removed);
    }
}

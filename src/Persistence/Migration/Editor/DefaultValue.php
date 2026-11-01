<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;

/**
 * A default an operation pins, in stored form: a scalar, an owned object's
 * identifier, null, or a Collection's items as a list. Wrapped, so "no
 * default given" is never a magic value.
 */
final readonly class DefaultValue
{
    public function __construct(
        public mixed $value,
    ) {
    }

    /**
     * Throws unless it can be $field's default, on type $type: a Collection
     * takes a list, of leaf items only for now. An Embed's or an owned
     * object's items have fields of their own, which a default can't give
     * yet (not built yet). What else a value holds is checked when the type
     * compiles.
     */
    public function assertFits(FieldDescriptor $field, string $type): void {
        if ($field->kind() !== FieldKind::Collection) {
            return;
        }

        if (!is_array($this->value) || !array_is_list($this->value)) {
            throw new InvalidOperation(sprintf('Field "%s" of "%s" is a Collection: its default is a list of its items, [] for none.', $field->name(), $type));
        }

        $item = $field->itemDescriptor()->kind();
        if ($this->value !== [] && ($item === FieldKind::Embed || $item === FieldKind::OwningReference)) {
            throw new InvalidOperation(sprintf('Field "%s" of "%s" holds Embeds or owned objects: a default with items isn\'t offered yet, only [].', $field->name(), $type));
        }
    }
}

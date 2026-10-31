<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * Every entry of an editor-created type getting, in field $to, what it holds
 * in field $from, as CopyValues copies it: `title` filled from `name`. Both
 * are the type's fields, its own or inherited, and hold the same thing, but
 * for nullability. $from keeps its values; the type's defaults don't change.
 * Fields only for now: no path into an Embed or an owned object, and no
 * owned object copied.
 */
final readonly class CopyValues implements SchemaOperation
{
    public FieldName $from;
    public FieldName $to;

    public function __construct(
        public string $type,
        string $from,
        string $to,
    ) {
        $this->from = FieldName::of($from);
        $this->to = FieldName::of($to);
    }

    public function applyTo(SchemaDraft $draft): void {
        $draft->editorType($this->type);
        if ((string) $this->from === (string) $this->to) {
            throw new InvalidOperation(sprintf('Field "%s" can\'t be copied onto itself.', $this->from));
        }

        $from = $draft->fieldOf($this->type, $this->from);
        $to = $draft->fieldOf($this->type, $this->to);
        if (self::ownsObjects($from)) {
            throw new InvalidOperation(sprintf('Field "%s" of "%s" holds owned objects: copying them isn\'t built yet.', $this->from, $this->type));
        }

        if (!$from->renamed((string) $this->to)->differsOnlyInNullability($to)) {
            throw new InvalidOperation(sprintf('Fields "%s" and "%s" of "%s" hold different things: retype one first.', $this->from, $this->to, $this->type));
        }

        if ($draft->isCreated($this->type)) {
            throw new InvalidOperation(sprintf('Type "%s" is new in this save: it has no entries to copy.', $this->type));
        }

        $draft->fixup(new Fixup\CopyValues($this->type, (string) $this->from, (string) $this->to));
    }

    /** Whether $field holds owned objects, itself or as a Collection's items. */
    private static function ownsObjects(FieldDescriptor $field): bool {
        return $field->kind() === FieldKind::OwningReference
            || ($field->kind() === FieldKind::Collection && $field->itemDescriptor()->kind() === FieldKind::OwningReference);
    }

    public function describe(): string {
        return sprintf('CopyValues(%s, %s → %s)', $this->type, $this->from, $this->to);
    }
}

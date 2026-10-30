<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultChange;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Pins the default of a field an operation gives a new shape, for AddField
 * and RetypeField. An editor-created field has no class to take a default
 * from, so the save says it: every kind needs one, but an Embed, whose
 * defaults are its fields', set by path.
 */
final class NewDefaults
{
    /** Pins $default on $type's $field, refusing a missing one, or one an Embed can't take. */
    public static function pin(SchemaDraft $draft, string $type, FieldDescriptor $field, ?DefaultValue $default): void {
        if ($field->kind() === FieldKind::Embed) {
            if ($default !== null) {
                throw new InvalidOperation(sprintf('Field "%s" of "%s" is an Embed: its defaults are its fields\', set by path.', $field->name(), $type));
            }

            return;
        }

        if ($default === null) {
            throw new InvalidOperation(sprintf('Field "%s" of "%s" needs a default: what new entries hold.', $field->name(), $type));
        }

        $draft->changeDefault(DefaultChange::pin($type, FieldPath::fromString($field->name()), $default));
    }
}

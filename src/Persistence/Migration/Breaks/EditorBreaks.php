<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Breaks;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\LostAncestorReason;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;

/**
 * Finds what a migration's schemas break on editor-created types, from the
 * schemas alone, never from a fixup: whether each field's declared target
 * exists, whether each chain is whole, each ancestor there and still allowing
 * editor-created subtypes, and whether a field a type declares clashes with
 * one it inherits. So any fixup, one written later included, takes part.
 */
final class EditorBreaks
{
    /**
     * Every break the editor-created types have in $schemas.
     * @param SchemaState|null $before The schemas before the fixup being
     *        checked: where an ancestor gone from $schemas is still found, so
     *        a chain can be walked past it. Null to read $schemas only.
     * @return array<string, EditorBreak> Keyed by key().
     */
    public static function in(SchemaState $schemas, ?SchemaState $before = null): array {
        $breaks = [];
        foreach ($schemas->schemas() as $identifier => $schema) {
            if ($schemas->sourceOf((string) $identifier) !== SchemaSource::Editor) {
                continue;
            }

            $cut = self::chainCut($schema, $schemas, $before ?? $schemas);
            if ($cut !== null) {
                $breaks[$cut->key()] = $cut;
            }

            foreach ($schema->fields as $name => $field) {
                $gone = self::targetGone($field, $schemas);
                if ($gone !== null) {
                    $break = new FieldTargetGone($schema->identifier, FieldName::of((string) $name), $gone);
                    $breaks[$break->key()] = $break;
                }

                // A clash is only told once the chain is whole: a cut one is resolved first.
                $ancestor = $cut === null ? self::clashingAncestor($schema, FieldName::of((string) $name), $schemas) : null;
                if ($ancestor !== null) {
                    $break = new FieldNameClash($schema->identifier, FieldName::of((string) $name), $ancestor);
                    $breaks[$break->key()] = $break;
                }
            }
        }

        return $breaks;
    }

    /**
     * $schema's chain cut, if it is: each ancestor walked past, nearest first,
     * until one that's there and allows editor-created subtypes.
     */
    private static function chainCut(EntitySchema $schema, SchemaState $schemas, SchemaState $before): ?ChainCut {
        $lost = [];
        $newParent = null;
        for ($ancestor = $schema->parent; $ancestor !== null; $ancestor = self::parentOf($ancestor, $schemas, $before)) {
            if ($schemas->has($ancestor) && self::allowsExtending($ancestor, $schemas)) {
                $newParent = $ancestor;
                break;
            }

            $lost[] = [$ancestor, $schemas->has($ancestor) ? LostAncestorReason::NotExtensible : LostAncestorReason::Dropped];
        }

        if ($lost === []) {
            return null;
        }

        // What the new chain still holds, the same, the type keeps.
        $kept = [];
        foreach ($newParent === null ? [] : $schemas->hierarchyFieldsOf($newParent) as $name => $field) {
            $kept[(string) $name] = $field;
        }

        $lostFields = [];
        foreach ($lost as [$ancestor]) {
            $level = $schemas->has($ancestor) ? $schemas->get($ancestor) : ($before->has($ancestor) ? $before->get($ancestor) : null);
            foreach ($level?->fields ?? [] as $name => $field) {
                if (!isset($kept[(string) $name]) || $kept[(string) $name] != $field) {
                    $lostFields[] = [FieldName::of((string) $name), $ancestor];
                }
            }
        }

        return new ChainCut($schema->identifier, $lost, $newParent, $lostFields);
    }

    /** $identifier's parent, read where it's still found. */
    private static function parentOf(string $identifier, SchemaState $schemas, SchemaState $before): ?string {
        return match (true) {
            $schemas->has($identifier) => $schemas->get($identifier)->parent,
            $before->has($identifier) => $before->get($identifier)->parent,
            default => null,
        };
    }

    /**
     * Whether type $identifier may have editor-created subtypes: an
     * editor-created type always may; a native one as the code says, or as
     * stored once the code no longer has it.
     */
    private static function allowsExtending(string $identifier, SchemaState $schemas): bool {
        if ($schemas->sourceOf($identifier) === SchemaSource::Editor) {
            return true;
        }

        return ($schemas->targetSchemas()[$identifier] ?? $schemas->get($identifier))->editorExtensible;
    }

    /** The type $field declares, as its target or its items', that's gone; null when there's none. */
    private static function targetGone(FieldDescriptor $field, SchemaState $schemas): ?string {
        $declared = $field->kind() === FieldKind::Collection ? $field->itemDescriptor() : $field;
        if (!in_array($declared->kind(), [FieldKind::Embed, FieldKind::Reference, FieldKind::OwningReference], true)) {
            return null;
        }

        return $schemas->has($declared->target()) ? null : $declared->target();
    }

    /** The nearest ancestor of $schema declaring a field named $name; null when none does. */
    private static function clashingAncestor(EntitySchema $schema, FieldName $name, SchemaState $schemas): ?string {
        for ($ancestor = $schema->parent; $ancestor !== null && $schemas->has($ancestor); $ancestor = $schemas->get($ancestor)->parent) {
            if ($schemas->get($ancestor)->fields->has($name)) {
                return $ancestor;
            }
        }

        return null;
    }
}

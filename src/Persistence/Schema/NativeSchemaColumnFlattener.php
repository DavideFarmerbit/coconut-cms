<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;

/**
 * Flattens one native class's own fields into the columns that end up on its
 * actual table, recursing through Embed with the usual column-name prefixing.
 * OwningReference and Collection never contribute a column here: an
 * OwningReference is found through the owned row's own owner/owner_field, and a
 * Collection's items live in their own child table, one row per item. Both come
 * back under "external" instead, keyed by the column prefix they'd otherwise have
 * used, naming whichever other schema needs its own separate tracking.
 */
final class NativeSchemaColumnFlattener
{
    /**
     * @param array<string, FieldDescriptor> $fields This class's own fields, CTI level.
     * @param array<string, mixed> $defaultValues This class's own default values; may
     *        carry extra keys beyond $fields, ignored.
     * @return array{columns: array<string, mixed>, external: array<string, string>}
     */
    public static function flatten(array $fields, array $defaultValues, string $prefix = ''): array {
        $columns = [];
        $external = [];

        foreach ($fields as $name => $field) {
            $value = $defaultValues[$name] ?? null;
            $columnName = $prefix . $name;

            match ($field->kind()) {
                FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => $columns[$columnName] = $value,
                FieldKind::Embed => self::mergeEmbed($field->target(), $value, $columnName . '_', $columns, $external),
                FieldKind::OwningReference => $external[$columnName] = is_array($value) ? $value['class'] : $field->target(),
                FieldKind::Collection => $external[$columnName] = self::externalNameFor($field->itemDescriptor()),
            };
        }

        return ['columns' => $columns, 'external' => $external];
    }

    /**
     * @param array<string, mixed>|null $value
     * @param array<string, mixed> $columns
     * @param array<string, string> $external
     */
    private static function mergeEmbed(string $target, ?array $value, string $prefix, array &$columns, array &$external): void {
        $nested = self::flatten(self::hierarchyFieldsOf($target), $value ?? [], $prefix);
        $columns += $nested['columns'];
        $external += $nested['external'];
    }

    private static function externalNameFor(FieldDescriptor $itemDescriptor): string {
        return match ($itemDescriptor->kind()) {
            FieldKind::Embed, FieldKind::Reference, FieldKind::OwningReference => $itemDescriptor->target(),
            default => $itemDescriptor->kind()->name,
        };
    }

    /**
     * $target's own fields plus everything it inherits, resolved transitively all
     * the way up: what an Embed target needs, since it has no separate ancestor
     * table of its own to leave inherited fields on.
     * @param class-string $target
     * @return array<string, FieldDescriptor>
     */
    private static function hierarchyFieldsOf(string $target): array {
        $schema = EntityRegistrar::register($target);
        $inherited = $schema->parent !== null ? self::hierarchyFieldsOf($schema->parent) : [];

        return [...$inherited, ...$schema->fields];
    }
}

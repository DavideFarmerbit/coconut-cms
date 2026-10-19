<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use Closure;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;

/**
 * Flattens one class's own fields into the columns that end up on its actual
 * table, recursing through Embed with the usual column-name prefixing.
 * OwningReference and Collection never contribute a column here: an
 * OwningReference is found through the owned row's own owner/owner_field, and a
 * Collection's items live in their own child table, one row per item. Both come
 * back under "external" instead, keyed by the column prefix they'd otherwise have
 * used, naming whichever other schema needs its own separate tracking.
 *
 * Resolving an Embed target's own hierarchy fields is injected rather than
 * hardcoded: the caller already has every blueprint/schema in its own working
 * set (the current batch, or the registry for anything compiled earlier), native
 * or editor-created alike, so this class never re-reads a class that's already
 * been read, and never needs to know or care which source produced it.
 */
final class ColumnFlattener
{
    /** @param Closure(class-string): array<string, FieldDescriptor> $hierarchyFieldsOf */
    public function __construct(
        private readonly Closure $hierarchyFieldsOf,
    ) {
    }

    /**
     * @param array<string, FieldDescriptor> $fields
     * @param array<string, mixed> $defaultValues This class's own default values; may
     *        carry extra keys beyond $fields, ignored.
     * @return array{columns: array<string, mixed>, external: array<string, string>}
     */
    public function flatten(array $fields, array $defaultValues, string $prefix = ''): array {
        $columns = [];
        $external = [];

        foreach ($fields as $name => $field) {
            $value = $defaultValues[$name] ?? null;
            $columnName = $prefix . $name;

            match ($field->kind()) {
                FieldKind::Scalar, FieldKind::ValueObject, FieldKind::Reference => $columns[$columnName] = $value,
                FieldKind::Embed => $this->mergeEmbed($field->target(), $value, $columnName . '_', $columns, $external),
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
    private function mergeEmbed(string $target, ?array $value, string $prefix, array &$columns, array &$external): void {
        $nested = $this->flatten(($this->hierarchyFieldsOf)($target), $value ?? [], $prefix);
        $columns += $nested['columns'];
        $external += $nested['external'];
    }

    private static function externalNameFor(FieldDescriptor $itemDescriptor): string {
        return match ($itemDescriptor->kind()) {
            FieldKind::Embed, FieldKind::Reference, FieldKind::OwningReference => $itemDescriptor->target(),
            default => $itemDescriptor->kind()->name,
        };
    }
}

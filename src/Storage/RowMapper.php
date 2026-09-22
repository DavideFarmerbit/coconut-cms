<?php

namespace XyloIsCoding\CoconutCms\Storage;

use JsonException;
use ReflectionClass;
use ReflectionObject;
use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;

/**
 * Converts between a name => value array matching a FieldDescriptor[] shape and the
 * actual database row (real columns plus the JSON blob column), in both directions.
 * Mirrors SchemaBuilder's own column-naming and blob-nesting rules exactly, since a row
 * built by one only round-trips correctly through the other if they agree.
 */
final class RowMapper
{
    /**
     * @param FieldDescriptor[] $fields
     * @param array<string, mixed> $values name => value, matching $fields
     * @return array<string, mixed> the database row, column name => value
     */
    public static function toRow(array $fields, array $values): array
    {
        $row = [];
        $blob = self::split($fields, $values, '', $row);
        $row[SchemaBuilder::BLOB_COLUMN] = self::encode($blob);

        return $row;
    }

    /**
     * @param FieldDescriptor[] $fields
     * @param array<string, mixed> $row the database row, column name => value
     * @return array<string, mixed> name => value, matching $fields
     */
    public static function fromRow(array $fields, array $row): array
    {
        $blob = self::decode((string) $row[SchemaBuilder::BLOB_COLUMN]);

        return self::join($fields, $row, $blob, '');
    }

    /**
     * Fills $row (by reference) with every queryable field's value and returns the
     * blob subtree for the fields that aren't.
     *
     * @param FieldDescriptor[] $fields
     * @param array<string, mixed> $values
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function split(array $fields, array $values, string $prefix, array &$row): array
    {
        $blob = [];

        foreach ($fields as $field) {
            $value = $values[$field->name] ?? null;

            if ($field->kind === FieldKind::EmbeddedValueObject) {
                $nestedFields = PrototypeShape::ofClass($field->referencedShape);
                $nestedValues = $value === null ? [] : self::propertiesOf($value, $nestedFields);
                $nestedBlob = self::split($nestedFields, $nestedValues, $prefix . $field->name . '_', $row);

                if ($nestedBlob !== []) {
                    $blob[$field->name] = $nestedBlob;
                }

                continue;
            }

            if ($field->queryable) {
                $row[$prefix . $field->name] = self::toColumnValue($field, $value);
            } else {
                $blob[$field->name] = $value;
            }
        }

        return $blob;
    }

    /**
     * @param FieldDescriptor[] $fields
     * @param array<string, mixed> $row
     * @param array<string, mixed> $blob
     * @return array<string, mixed>
     */
    private static function join(array $fields, array $row, array $blob, string $prefix): array
    {
        $values = [];

        foreach ($fields as $field) {
            if ($field->kind === FieldKind::EmbeddedValueObject) {
                $nestedFields = PrototypeShape::ofClass($field->referencedShape);
                $nestedValues = self::join($nestedFields, $row, $blob[$field->name] ?? [], $prefix . $field->name . '_');
                $values[$field->name] = (new ReflectionClass($field->referencedShape))->newInstanceArgs($nestedValues);

                continue;
            }

            $values[$field->name] = $field->queryable
                ? self::fromColumnValue($field, $row[$prefix . $field->name] ?? null)
                : ($blob[$field->name] ?? null);
        }

        return $values;
    }

    /** SQLite/MySQL have no native boolean, so it round-trips through the column as 0/1. */
    private static function toColumnValue(FieldDescriptor $field, mixed $value): mixed
    {
        return $field->kind === FieldKind::Bool && $value !== null ? (int) $value : $value;
    }

    private static function fromColumnValue(FieldDescriptor $field, mixed $value): mixed
    {
        return $field->kind === FieldKind::Bool && $value !== null ? (bool) $value : $value;
    }

    /**
     * Reads a hydrated entity or value object's own property values back out, by
     * name, matching its own FieldDescriptor[] shape.
     *
     * @param FieldDescriptor[] $fields
     * @return array<string, mixed>
     */
    public static function propertiesOf(object $object, array $fields): array
    {
        $reflection = new ReflectionObject($object);
        $values = [];

        foreach ($fields as $field) {
            $values[$field->name] = $reflection->getProperty($field->name)->getValue($object);
        }

        return $values;
    }

    /** @param array<string, mixed> $blob */
    private static function encode(array $blob): string
    {
        try {
            return json_encode($blob, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new JsonException(sprintf('Could not encode entity data as JSON: %s', $exception->getMessage()), previous: $exception);
        }
    }

    /** @return array<string, mixed> */
    private static function decode(string $blob): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($blob, associative: true, flags: JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException $exception) {
            throw new JsonException(sprintf('Could not decode entity data from JSON: %s', $exception->getMessage()), previous: $exception);
        }
    }
}

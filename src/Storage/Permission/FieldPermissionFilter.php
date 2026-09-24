<?php

namespace XyloIsCoding\CoconutCms\Storage\Permission;

use XyloIsCoding\CoconutCms\Storage\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Storage\Field\FieldKind;
use XyloIsCoding\CoconutCms\Storage\PrototypeShape;
use XyloIsCoding\CoconutCms\Storage\RowMapper;

/**
 * Produces a display projection of a hydrated entity's values with every field an
 * actor lacks read permission for dropped entirely, key absent, not just null, so an
 * actor without read permission for a field doesn't just see it disabled, they don't
 * see it exists.
 *
 * Deliberately separate from Repository::find(), which still has to construct the
 * full native object, a readonly class's constructor needs every one of its required
 * properties, there's no way to omit one and still get a valid instance. This is the
 * projection step that runs after that, when turning the domain object into whatever
 * gets shown (an editor form, an API response), not a replacement for hydration itself.
 */
final class FieldPermissionFilter
{
    /**
     * @param FieldDescriptor[] $fields
     * @param array<string, mixed> $values name => value, as RowMapper::propertiesOf() returns
     * @return array<string, mixed>
     */
    public static function forRead(array $fields, array $values, Actor $actor): array
    {
        $filtered = [];

        foreach ($fields as $field) {
            if (!array_key_exists($field->name, $values)) {
                continue;
            }

            if ($field->permission !== null && !$field->permission->canRead($actor)) {
                continue;
            }

            $filtered[$field->name] = self::projectValue($field, $values[$field->name], $actor);
        }

        return $filtered;
    }

    private static function projectValue(FieldDescriptor $field, mixed $value, Actor $actor): mixed
    {
        if ($field->kind !== FieldKind::EmbeddedValueObject || $value === null) {
            return $value;
        }

        $nestedFields = PrototypeShape::ofClass($field->referencedShape);

        return self::forRead($nestedFields, RowMapper::propertiesOf($value, $nestedFields), $actor);
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;

/**
 * Every value of an aggregate naming a type through a type reference, found
 * through the schemas as they are at one point of the migration, and what
 * the fixups changing types do with them: a rename rewrites them, and a
 * value naming a type that no longer fits its field is cleared.
 */
final class NamedTypes
{
    /**
     * Every value naming a type, on every object the aggregate holds.
     * @return list<NamedType>
     */
    public static function in(AggregateValues $values, SchemaState $schemas): array {
        $found = [];
        foreach (Occurrences::in($values, $schemas) as $occurrence) {
            if (!$schemas->has($occurrence->identifier)) {
                continue;
            }

            foreach ($schemas->hierarchyFieldsOf($occurrence->identifier) as $name => $field) {
                $path = $occurrence->fieldPath($name);
                if ($field->kind() === FieldKind::TypeReference && is_string($values->get($path))) {
                    $found[] = new NamedType($path, $values->get($path), $field, null);
                } elseif ($field->kind() === FieldKind::Collection && $field->itemDescriptor()->kind() === FieldKind::TypeReference) {
                    for ($index = 0; $index < (int) $values->get($path); $index++) {
                        $item = $path->traverse($index);
                        if (is_string($values->get($item))) {
                            $found[] = new NamedType($item, $values->get($item), $field->itemDescriptor(), $path);
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Rewrites each value naming a type `$renames` maps, all at once.
     * @param array<string, string> $renames Each old identifier's new one.
     */
    public static function rename(AggregateValues $values, SchemaState $schemas, array $renames): void {
        foreach (self::in($values, $schemas) as $named) {
            if (isset($renames[$named->identifier])) {
                $values->set($named->path, $renames[$named->identifier], $values->markOf($named->path));
            }
        }
    }

    /**
     * Clears each value naming a type that, in `$schemas`, is gone or no
     * longer is or extends its field's base: a nullable one becomes null, a
     * required one follows its default, a required Collection item goes.
     */
    public static function clearUnfit(AggregateValues $values, SchemaState $schemas): void {
        $collections = [];
        foreach (self::in($values, $schemas) as $named) {
            if ($schemas->has($named->identifier) && $schemas->isA($named->identifier, $named->field->target())) {
                continue;
            }

            if ($named->collection === null && !$named->field->allowsNull()) {
                $values->removeAt($named->path);
                continue;
            }

            $values->replaceAt($named->path, null, ValueMark::Converted);
            if ($named->collection !== null && !$named->field->allowsNull()) {
                $collections[(string) $named->collection] = $named->collection;
            }
        }

        foreach ($collections as $collection) {
            $values->removeNullItems($collection);
        }
    }
}

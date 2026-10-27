<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * A type's own field, or its Collection's items, no longer holding null.
 * Every null goes: a singular one becomes untouched, so it follows the new
 * default; a null item is removed, and the Collection gets shorter. Every
 * other value stays. Chosen by the unsafe policy; declare it in a step to
 * accept that on any database.
 */
final readonly class MakeNotNullable implements Fixup, MovesValues
{
    public FieldName $field;

    public function __construct(
        public string $type,
        string $field,
    ) {
        $this->field = FieldName::of($field);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $field = $schemas->field($this->type, $this->field);
        if ($field->ownOrItemNullability() !== Nullability::Nullable) {
            throw new InvalidFixup(sprintf('field "%s" of type "%s" can\'t hold null at this point.', $this->field, $this->type));
        }

        if (($field->kind() === FieldKind::Collection ? $field->itemDescriptor() : $field)->kind() === FieldKind::Reference) {
            throw new InvalidFixup(sprintf('field "%s" of type "%s" is a Reference: it\'s always Nullable.', $this->field, $this->type));
        }

        $schema = $schemas->get($this->type);

        return $schemas->with($schema->withFields($schema->fields->with($this->field, $field->withNullability(Nullability::NotNullable))));
    }

    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        $field = $before->field($this->type, $this->field);

        foreach (Occurrences::ofType($this->type, $values, $before) as $occurrence) {
            $path = $occurrence->fieldPath($this->field);
            if ($field->kind() === FieldKind::Collection) {
                self::removeNullItems($values, $path);
            } elseif ($values->has($path) && $values->get($path) === null) {
                $values->removeAt($path);
            }
        }
    }

    public function movedTypes(): array {
        return [$this->type];
    }

    public function describe(): string {
        return sprintf('MakeNotNullable(%s, %s)', $this->type, $this->field);
    }

    /**
     * Removes the Collection at $path's null items, moving the ones after
     * each up. One that follows its default is left alone: the new default
     * holds no null.
     */
    private static function removeNullItems(AggregateValues $values, FieldPath $path): void {
        $mark = $values->markOf($path);
        if ($mark->isDefault() || !$values->has($path)) {
            return;
        }

        $count = (int) $values->get($path);
        $kept = 0;
        for ($index = 0; $index < $count; $index++) {
            $item = $path->traverse($index);
            if ($values->get($item) === null) {
                $values->removeAt($item);
                continue;
            }

            $values->move($item, $path->traverse($kept));
            $kept++;
        }

        $values->set($path, $kept, $mark);
    }
}

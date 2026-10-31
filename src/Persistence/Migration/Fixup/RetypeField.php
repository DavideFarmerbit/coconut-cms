<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Values;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * A type's own field changing what it holds: its kind, target, scalar type,
 * enum, or item. converting() turns every old value into a new one, nothing
 * implicit; reset() drops every value, so every entity follows the new
 * default.
 */
final readonly class RetypeField implements Fixup, MovesValues
{
    public FieldName $field;

    private function __construct(
        public string $type,
        string $field,
        private ?FieldConverter $converter,
    ) {
        $this->field = FieldName::of($field);
    }

    /** Every value converted by $converter, wherever the field lives, touched or following its old default. */
    public static function converting(string $type, string $field, FieldConverter $converter): self {
        return new self($type, $field, $converter);
    }

    /** Every value dropped: every entity follows the field's new default. */
    public static function reset(string $type, string $field): self {
        return new self($type, $field, null);
    }

    /** Throws InvalidFixup when the converter doesn't take the field as it is, or give it as it becomes. */
    public function applyTo(SchemaState $schemas): SchemaState {
        $old = $schemas->field($this->type, $this->field);
        $new = $schemas->targetField($this->type, $this->field);
        if ($this->converter !== null) {
            $this->converter::from()->assertMatches($old, $this->converter::class, 'takes', $this->type, (string) $this->field);
            $this->converter::to()->assertMatches($new, $this->converter::class, 'gives', $this->type, (string) $this->field);
        }

        $schema = $schemas->get($this->type);

        return $schemas->with($schema->withFields($schema->fields->with($this->field, $new)));
    }

    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        $old = $before->field($this->type, $this->field);
        $new = $after->field($this->type, $this->field);

        foreach (Occurrences::ofType($this->type, $values, $before) as $occurrence) {
            $path = $occurrence->fieldPath($this->field);
            if ($this->converter === null) {
                $values->removeAt($path);
                continue;
            }

            $under = $values->valuesUnder($path);
            // An Embed has no value of its own: it follows its default while
            // nothing under it is touched.
            $isDefault = $old->kind() === FieldKind::Embed
                ? self::allDefault($under)
                : $values->markOf($path)->isDefault();

            $values->write($path, $new, $this->converter->convert(new Captured($values->get($path), $isDefault, $under), $new));
        }
    }

    public function movedTypes(): array {
        return [$this->type];
    }

    public function describe(): string {
        return $this->converter === null
            ? sprintf('RetypeField::reset(%s, %s)', $this->type, $this->field)
            : sprintf('RetypeField::converting(%s, %s, %s)', $this->type, $this->field, $this->converter::class);
    }

    private static function allDefault(Values $values): bool {
        foreach ($values->paths() as $path) {
            if (!$values->isDefault($path)) {
                return false;
            }
        }

        return true;
    }
}

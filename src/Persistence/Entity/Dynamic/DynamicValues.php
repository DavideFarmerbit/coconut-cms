<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic;

use BackedEnum;
use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\IdentityMap;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Checks a value DynamicEntity::set() is given against its field, and puts
 * it in the form each part holds: a native view's property, or the bag.
 * Values come in the form get() gives: an Embed or an owned object as an
 * EntityView, a DynamicEntity or a native object; a Reference as its
 * target, in either form.
 */
final readonly class DynamicValues
{
    public function __construct(
        private SchemaLookup $schemas,
        private IdentityMap $identities,
        private DynamicEntities $entities,
    ) {
    }

    /**
     * Throws InvalidValue, naming $at, when $field can't hold $value: the
     * checks PHP makes on a typed property, and the target types an entity
     * must be of.
     */
    public function check(FieldDescriptor $field, mixed $value, string $at): void {
        if ($value === null) {
            if (!$field->allowsNull()) {
                throw new InvalidValue($at, 'it may not be empty.');
            }

            return;
        }

        match ($field->kind()) {
            FieldKind::Scalar => $this->checkScalar($field, $value, $at),
            FieldKind::ValueObject => $value instanceof ($field->target()) ? null : throw new InvalidValue($at, sprintf('it holds a %s.', $field->target())),
            FieldKind::Reference => $this->checkReference($field, $value, $at),
            FieldKind::Embed, FieldKind::OwningReference => $this->checkEntity($field, $value, $at),
            FieldKind::Collection => $this->checkItems($field, $value, $at),
        };
    }

    /** $value, checked, as a native view's property holds it: native objects where it holds entities. */
    public function forView(FieldDescriptor $field, mixed $value, string $at): mixed {
        return match ($field->kind()) {
            FieldKind::Embed, FieldKind::OwningReference, FieldKind::Reference => $value instanceof DynamicEntity
                ? ($value->view() ?? throw new InvalidValue($at, sprintf('a %s has no native class, and this field is native.', $value->type())))
                : $value,
            FieldKind::Collection => array_map(
                fn (mixed $item): mixed => $this->forView($field->itemDescriptor(), $item, $at),
                $value,
            ),
            default => $value,
        };
    }

    /** $value, checked, as the bag holds it: the form get() gives, an Embed or owned object as its DynamicEntity, a Reference as its target's id. */
    public function forBag(FieldDescriptor $field, mixed $value): mixed {
        if ($value === null) {
            return null;
        }

        return match ($field->kind()) {
            FieldKind::Embed, FieldKind::OwningReference => $value instanceof DynamicEntity ? $value : $this->entities->of($value),
            FieldKind::Reference => $this->idOf($value),
            FieldKind::Collection => array_map(fn (mixed $item): mixed => $this->forBag($field->itemDescriptor(), $item), $value),
            default => $value,
        };
    }

    private function checkScalar(FieldDescriptor $field, mixed $value, string $at): void {
        try {
            ScalarValues::toStorage($field->scalarType(), $value);
        } catch (InvalidArgumentException $wrong) {
            throw new InvalidValue($at, $wrong->getMessage());
        }

        $enum = $field->enumClass();
        if ($field->scalarType() === ScalarType::Enum && $enum !== null && !($value instanceof BackedEnum && $value instanceof $enum)) {
            throw new InvalidValue($at, sprintf('it holds a %s.', $enum));
        }
    }

    /** A Reference holds a saved entity of its target type, or one extending it. */
    private function checkReference(FieldDescriptor $field, mixed $value, string $at): void {
        $this->checkEntity($field, $value, $at);
        if ($this->idOf($value) === null) {
            throw new InvalidValue($at, 'the entity was never saved: save it first, so it has an id to point at.');
        }
    }

    /** An Embed, an owned object or a Reference's target: an entity of the field's target type, or one extending it. */
    private function checkEntity(FieldDescriptor $field, mixed $value, string $at): void {
        if (!is_object($value)) {
            throw new InvalidValue($at, sprintf('it holds a %s, as an entity.', $field->target()));
        }

        $type = $this->typeOf($value);
        if ($type === null || !$this->isA($type, $field->target())) {
            throw new InvalidValue($at, sprintf('it holds a %s or a type extending it, not a %s.', $field->target(), $type ?? $value::class));
        }
    }

    private function checkItems(FieldDescriptor $field, mixed $value, string $at): void {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidValue($at, 'a Collection holds a list.');
        }

        foreach ($value as $index => $item) {
            $this->check($field->itemDescriptor(), $item, $at . FieldPath::TRAVERSE . $index);
        }
    }

    /** The type of the entity $value is; null when it's no entity. */
    private function typeOf(object $value): ?string {
        if ($value instanceof DynamicEntity) {
            return $value->type();
        }

        try {
            return $this->identities->typeOf($value) ?? NativeIdentifiers::of($value::class);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** The id of the entity $value is, a DynamicEntity or an object; null when it has none yet. */
    private function idOf(object $value): ?string {
        return $value instanceof DynamicEntity ? $value->id() : $this->identities->idOf($value);
    }

    private function isA(string $type, string $ancestor): bool {
        for ($current = $type; $current !== null; $current = $this->schemas->has($current) ? $this->schemas->get($current)->parent : null) {
            if ($current === $ancestor) {
                return true;
            }
        }

        return false;
    }
}

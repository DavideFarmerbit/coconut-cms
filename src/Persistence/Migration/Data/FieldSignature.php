<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * The shape of the fields a converter takes or gives, or an initializer
 * fills: a field descriptor's shape, each part a value or any, or one of
 * several such shapes. What leaves something out matches any: `scalar()` any
 * Scalar, `scalar(ScalarType::Int)` an Int, `reference()` a Reference to any
 * type, `collection(scalar())` a Collection of any Scalars. `oneOf()` matches
 * any of its signatures: `text()` and `number()` are the common ones. Not a
 * FieldDescriptor: that describes one real field, this a set of them.
 * Nullability is never part of it.
 */
final readonly class FieldSignature
{
    /**
     * @param FieldKind|null $kind Null for any kind, or for a union.
     * @param ScalarType|null $scalarType A Scalar's type; null for any.
     * @param string|null $target An Embed's, a Reference's or an owned
     *        object's type, a value object's class; null for any.
     * @param FieldSignature|null $item A Collection's items; null for any.
     * @param list<FieldSignature> $oneOf A union's signatures; empty for none.
     */
    private function __construct(
        public ?FieldKind $kind,
        public ?ScalarType $scalarType = null,
        public ?string $target = null,
        public ?FieldSignature $item = null,
        public array $oneOf = [],
    ) {
    }

    /** Any field. */
    public static function any(): self {
        return new self(null);
    }

    /** A Scalar of $type; of any type when null. */
    public static function scalar(?ScalarType $type = null): self {
        return new self(FieldKind::Scalar, $type);
    }

    /** @param class-string|null $class A value object of $class; of any class when null. */
    public static function valueObject(?string $class = null): self {
        return new self(FieldKind::ValueObject, target: $class);
    }

    public static function embed(?string $type = null): self {
        return new self(FieldKind::Embed, target: $type);
    }

    public static function reference(?string $type = null): self {
        return new self(FieldKind::Reference, target: $type);
    }

    public static function owningReference(?string $type = null): self {
        return new self(FieldKind::OwningReference, target: $type);
    }

    /** A Collection of items matching $item; of any items when null. */
    public static function collection(?self $item = null): self {
        return new self(FieldKind::Collection, item: $item);
    }

    /** A field matching any of $signatures. */
    public static function oneOf(self ...$signatures): self {
        return new self(null, oneOf: array_values($signatures));
    }

    /** A String or a Text. */
    public static function text(): self {
        return self::oneOf(self::scalar(ScalarType::String), self::scalar(ScalarType::Text));
    }

    /** An Int, a Float or a Decimal. */
    public static function number(): self {
        return self::oneOf(self::scalar(ScalarType::Int), self::scalar(ScalarType::Float), self::scalar(ScalarType::Decimal));
    }

    /** Whether it's a union of others. */
    public function isUnion(): bool {
        return $this->oneOf !== [];
    }

    /** Whether $field is one of the fields this describes. */
    public function matches(FieldDescriptor $field): bool {
        if ($this->isUnion()) {
            foreach ($this->oneOf as $signature) {
                if ($signature->matches($field)) {
                    return true;
                }
            }

            return false;
        }

        if ($this->kind === null) {
            return true;
        }

        if ($field->kind() !== $this->kind) {
            return false;
        }

        return match ($this->kind) {
            FieldKind::Scalar => $this->scalarType === null || $field->scalarType() === $this->scalarType,
            FieldKind::Collection => $this->item === null || $this->item->matches($field->itemDescriptor()),
            default => $this->target === null || $field->target() === $this->target,
        };
    }

    /**
     * Throws InvalidFixup unless $field matches: $what, a converter or
     * initializer, $role the field ("takes", "gives", "fills"), named as
     * field $name of type $type.
     */
    public function assertMatches(FieldDescriptor $field, string $what, string $role, string $type, string $name): void {
        if (!$this->matches($field)) {
            throw new InvalidFixup(sprintf('%s %s %s, and field "%s" of "%s" is not one.', $what, $role, $this->describe(), $name, $type));
        }
    }

    /** How a refusal names it: "a Scalar of int", "a Scalar of int, float or decimal", "a Collection of any items". */
    public function describe(): string {
        if ($this->isUnion()) {
            $scalarTypes = array_map(static fn (self $signature): ?string => $signature->kind === FieldKind::Scalar && !$signature->isUnion() ? $signature->scalarType?->value : null, $this->oneOf);
            if (!in_array(null, $scalarTypes, true)) {
                return 'a Scalar of ' . self::listed($scalarTypes);
            }

            return self::listed(array_map(static fn (self $signature): string => $signature->describe(), $this->oneOf));
        }

        if ($this->kind === null) {
            return 'any field';
        }

        $kind = match ($this->kind) {
            FieldKind::Scalar => 'a Scalar',
            FieldKind::ValueObject => 'a value object',
            FieldKind::Embed => 'an Embed',
            FieldKind::Reference => 'a Reference',
            FieldKind::OwningReference => 'an owned object',
            FieldKind::Collection => 'a Collection',
        };

        return match ($this->kind) {
            FieldKind::Scalar => $this->scalarType === null ? $kind : sprintf('%s of %s', $kind, $this->scalarType->value),
            FieldKind::Collection => sprintf('%s of %s', $kind, $this->item?->describe() ?? 'any items'),
            default => $this->target === null ? $kind : sprintf('%s of %s', $kind, $this->target),
        };
    }

    /**
     * $items as a sentence lists them: "a", "a or b", "a, b or c".
     * @param list<string> $items
     */
    private static function listed(array $items): string {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items) . ' or ' . $last;
    }
}

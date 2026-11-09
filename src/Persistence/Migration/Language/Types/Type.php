<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * The type of a field or of an expression in the change language. Written
 * the way a change file writes it: `decimal`, `enum shop:currency?`,
 * `list owns shop:wheel`. A list is never null itself: its items can be.
 */
final readonly class Type
{
    private function __construct(
        public TypeKind $kind,
        public ?ScalarType $scalar = null,
        public ?string $name = null,
        public ?Type $item = null,
        public bool $nullable = false,
    ) {
    }

    /** A scalar; an Enum names its enum. */
    public static function scalar(ScalarType $scalar, ?string $enum = null): self {
        if (($scalar === ScalarType::Enum) !== ($enum !== null)) {
            throw new InvalidArgumentException('An enum type names its enum, and only an enum type does.');
        }

        return new self(TypeKind::Scalar, $scalar, $enum);
    }

    public static function text(): self {
        return self::scalar(ScalarType::Text);
    }

    public static function int(): self {
        return self::scalar(ScalarType::Int);
    }

    public static function bool(): self {
        return self::scalar(ScalarType::Bool);
    }

    public static function decimal(): self {
        return self::scalar(ScalarType::Decimal);
    }

    public static function float(): self {
        return self::scalar(ScalarType::Float);
    }

    public static function list(self $item): self {
        return new self(TypeKind::List, item: $item);
    }

    public static function embed(string $type): self {
        return new self(TypeKind::Embed, name: $type);
    }

    public static function owned(string $type): self {
        return new self(TypeKind::Owned, name: $type);
    }

    public static function reference(string $type): self {
        return new self(TypeKind::Reference, name: $type);
    }

    public static function typeReference(string $base): self {
        return new self(TypeKind::TypeReference, name: $base);
    }

    public static function value(string $name): self {
        return new self(TypeKind::Value, name: $name);
    }

    public static function null(): self {
        return new self(TypeKind::Null);
    }

    public static function default(): self {
        return new self(TypeKind::Default);
    }

    public static function never(): self {
        return new self(TypeKind::Never);
    }

    /** This type, also holding null. A list, null, default and never stay as they are. */
    public function orNull(): self {
        if (in_array($this->kind, [TypeKind::List, TypeKind::Null, TypeKind::Default, TypeKind::Never], true)) {
            return $this;
        }

        return new self($this->kind, $this->scalar, $this->name, $this->item, true);
    }

    /** This type without null. */
    public function nonNull(): self {
        return new self($this->kind, $this->scalar, $this->name, $this->item, false);
    }

    /** Whether it's one of these scalars, null or not. */
    public function is(ScalarType ...$scalars): bool {
        return $this->kind === TypeKind::Scalar && in_array($this->scalar, $scalars, true);
    }

    /** Whether it's an Int, a Float or a Decimal, null or not. */
    public function isNumber(): bool {
        return $this->is(ScalarType::Int, ScalarType::Float, ScalarType::Decimal);
    }

    /** Whether it's a String or a Text, null or not. */
    public function isText(): bool {
        return $this->is(ScalarType::String, ScalarType::Text);
    }

    /** Whether it holds fields: an Embed or an owned object. */
    public function hasFields(): bool {
        return $this->kind === TypeKind::Embed || $this->kind === TypeKind::Owned;
    }

    public function equals(self $other): bool {
        return $this->describe() === $other->describe();
    }

    /** The type as a change file writes it. */
    public function describe(): string {
        $text = match ($this->kind) {
            TypeKind::Scalar => match ($this->scalar) {
                ScalarType::Enum => 'enum ' . $this->name,
                ScalarType::DateTime => 'datetime',
                default => $this->scalar->value,
            },
            TypeKind::List => 'list ' . $this->item->describe(),
            TypeKind::Null, TypeKind::Default, TypeKind::Never => $this->kind->value,
            default => $this->kind->value . ' ' . $this->name,
        };

        return $this->nullable ? $text . '?' : $text;
    }
}

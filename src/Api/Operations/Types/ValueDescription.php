<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Types;

use BackedEnum;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;

/**
 * What a field holds, as a client needs it to show and edit it: its kind,
 * and what that kind needs more. A Collection's items are described the same
 * way.
 */
final readonly class ValueDescription
{
    /**
     * @param ScalarType|null $scalarType A Scalar's type.
     * @param list<string|int>|null $enumValues An enum Scalar's values, in order.
     * @param string|null $target The type an Embed, a Reference or an owned
     *        object holds; a value object's registered identifier, never its
     *        class.
     * @param ValueDescription|null $item A Collection's items.
     */
    public function __construct(
        public FieldKind $kind,
        public bool $nullable,
        public ?ScalarType $scalarType = null,
        public ?array $enumValues = null,
        public ?string $target = null,
        public ?ValueDescription $item = null,
    ) {
    }

    /** What $field holds. */
    public static function of(FieldDescriptor $field): self {
        return match ($field->kind()) {
            FieldKind::Scalar => new self(
                FieldKind::Scalar,
                $field->allowsNull(),
                $field->scalarType(),
                $field->enumClass() === null ? null : array_map(static fn (BackedEnum $case): string|int => $case->value, $field->enumClass()::cases()),
            ),
            FieldKind::ValueObject => new self(FieldKind::ValueObject, $field->allowsNull(), target: (string) NativeIdentifiers::storedAs($field->target())),
            FieldKind::Embed, FieldKind::Reference, FieldKind::OwningReference => new self($field->kind(), $field->allowsNull(), target: $field->target()),
            FieldKind::Collection => new self(FieldKind::Collection, false, item: self::of($field->itemDescriptor())),
        };
    }
}

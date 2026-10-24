<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * Marks a native property as a Collection field: an ordered collection of items of one
 * other kind. Flattened, since attribute arguments must be compile-time constants: the
 * item's own kind plus whichever of $itemScalarType/$itemEnumClass/$itemTarget that
 * kind needs.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Collection
{
    /**
     * @param FieldKind $itemKind The item's own kind. Never FieldKind::Collection.
     * @param ScalarType|null $itemScalarType The item's primitive type, set when
     *        $itemKind is FieldKind::Scalar.
     * @param string|null $itemEnumClass The item's backed enum class, set when
     *        $itemScalarType is ScalarType::Enum.
     * @param string|null $itemTarget The item's target identifier, set when $itemKind
     *        is FieldKind::ValueObject, FieldKind::Embed, FieldKind::Reference, or
     *        FieldKind::OwningReference. PHP has no generic arrays, so unlike
     *        a singular field this can't be read off the property's own type. For
     *        FieldKind::ValueObject, a class implementing StorableValue.
     * @param Nullability|null $itemNullability Whether an item may be null: PHP
     *        can't say it for an array's items. Set when $itemKind is
     *        FieldKind::Scalar, FieldKind::ValueObject or
     *        FieldKind::OwningReference. Never for an Embed item, never null,
     *        or a Reference item, always Nullable.
     */
    public function __construct(
        public FieldKind $itemKind,
        public ?ScalarType $itemScalarType = null,
        public ?string $itemEnumClass = null,
        public ?string $itemTarget = null,
        public ?Nullability $itemNullability = null,
    ) {
    }
}

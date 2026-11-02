<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Parameters\ValueMapping;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;

/**
 * A Scalar field turned into another, for RetypeField, each value as
 * $mapping says, both in stored form: an enum's cases renamed, "yes" and
 * "no" into a Bool, sizes into numbers. A key matches by type and value, as
 * the old field stores it: a Decimal as "2.500000", a Bool as true. A value
 * not mapped does what $onFailure says. Null is mapped like any value: left
 * out, it stays null. A value following the old default follows the new
 * one. What a value is mapped to is checked against the new field before
 * anything is written.
 */
#[Converter('coconut-cms:map-values')]
final readonly class MapValues implements FieldConverter
{
    public function __construct(
        public ValueMapping $mapping,
        public OnFailure $onFailure,
    ) {
    }

    public static function from(): FieldSignature {
        return FieldSignature::scalar();
    }

    public static function to(): FieldSignature {
        return FieldSignature::scalar();
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        if ($old->isDefault()) {
            return Conversion::default();
        }

        $value = $old->value();
        if ($this->mapping->has($value)) {
            return Conversion::of($this->mapping->get($value));
        }

        if ($value === null) {
            return Conversion::of(null);
        }

        return $this->onFailure === OnFailure::Default ? Conversion::default() : throw ConversionFailed::of('MapValues', $value);
    }
}

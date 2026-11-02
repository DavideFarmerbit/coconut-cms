<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;

/**
 * An Enum field turned into text, for RetypeField: each case becomes the
 * value it's stored as, "red" for Color::Red, 2 as "2" for an int-backed
 * one. Null stays null; a value following the old default follows the new
 * one.
 */
#[Converter('coconut-cms:enum-to-text')]
final readonly class EnumToText implements FieldConverter
{
    public static function from(): FieldSignature {
        return FieldSignature::scalar(ScalarType::Enum);
    }

    public static function to(): FieldSignature {
        return FieldSignature::text();
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        if ($old->isDefault()) {
            return Conversion::default();
        }

        $value = $old->value();

        return Conversion::of($value === null ? null : (string) $value);
    }
}

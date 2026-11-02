<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;

/**
 * A field holding one value turned into a Collection of them, for
 * RetypeField: each value becomes the only item, a tag into the list of
 * tags. Null becomes no item. The item is checked against the new field's
 * items before anything is written. A value following the old default
 * follows the new one.
 */
#[Converter('coconut-cms:to-single-item')]
final readonly class ToSingleItem implements FieldConverter
{
    public static function from(): FieldSignature {
        return FieldSignature::leaf();
    }

    public static function to(): FieldSignature {
        return FieldSignature::collection(FieldSignature::leaf());
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        if ($old->isDefault()) {
            return Conversion::default();
        }

        $value = $old->value();

        return $value === null ? Conversion::of(0) : Conversion::of(1, ['0' => $value]);
    }
}

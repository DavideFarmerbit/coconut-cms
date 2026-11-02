<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;

/**
 * A Collection field turned into one holding a single value, for
 * RetypeField: each list becomes its first item, the others dropped. An
 * empty list follows the new default. The item is checked against the new
 * field before anything is written. A value following the old default
 * follows the new one.
 */
#[Converter('coconut-cms:first-item')]
final readonly class FirstItem implements FieldConverter
{
    public static function from(): FieldSignature {
        return FieldSignature::collection(FieldSignature::leaf());
    }

    public static function to(): FieldSignature {
        return FieldSignature::leaf();
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        if ($old->isDefault() || $old->value() === 0) {
            return Conversion::default();
        }

        return Conversion::of($old->under()->get('0'));
    }
}

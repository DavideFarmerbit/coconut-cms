<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Converter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;

/**
 * A String field turned into a Text one or back, for RetypeField. Every text
 * fits a Text. Text longer than a String's 255 characters does what
 * $onTooLong says: cut, the new default, or the migration cancelled. Null
 * stays null; a value following the old default follows the new one.
 */
#[Converter('coconut-cms:text-to-text')]
final readonly class TextToText implements FieldConverter
{
    public function __construct(
        public OnTooLong $onTooLong,
    ) {
    }

    public static function from(): FieldSignature {
        return FieldSignature::text();
    }

    public static function to(): FieldSignature {
        return FieldSignature::text();
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        if ($old->isDefault()) {
            return Conversion::default();
        }

        $value = $old->value();
        if ($value === null) {
            return Conversion::of(null);
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('TextToText converts text, got %s.', get_debug_type($value)));
        }

        if ($to->scalarType() === ScalarType::Text || mb_strlen($value) <= ScalarValues::MAX_STRING_LENGTH) {
            return Conversion::of($value);
        }

        return match ($this->onTooLong) {
            OnTooLong::Truncate => Conversion::of(mb_substr($value, 0, ScalarValues::MAX_STRING_LENGTH)),
            OnTooLong::Default => Conversion::default(),
            OnTooLong::Cancel => throw ConversionFailed::of('TextToText', $value),
        };
    }
}

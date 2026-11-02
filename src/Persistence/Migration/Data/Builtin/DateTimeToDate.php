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
 * A DateTime field turned into a Date one, for RetypeField: each moment
 * becomes its day in UTC, as a DateTime is stored, the time dropped. Null
 * stays null; a value following the old default follows the new one.
 */
#[Converter('coconut-cms:date-time-to-date')]
final readonly class DateTimeToDate implements FieldConverter
{
    public static function from(): FieldSignature {
        return FieldSignature::scalar(ScalarType::DateTime);
    }

    public static function to(): FieldSignature {
        return FieldSignature::scalar(ScalarType::Date);
    }

    public function convert(Captured $old, FieldDescriptor $to): Conversion {
        if ($old->isDefault()) {
            return Conversion::default();
        }

        $value = $old->value();
        if ($value === null) {
            return Conversion::of(null);
        }

        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2}) \d{2}:\d{2}:\d{2}$/', $value, $parts) !== 1) {
            throw new InvalidArgumentException(sprintf('DateTimeToDate converts a DateTime stored as "%s", got %s.', ScalarValues::DATE_TIME_FORMAT, json_encode($value)));
        }

        return Conversion::of($parts[1]);
    }
}

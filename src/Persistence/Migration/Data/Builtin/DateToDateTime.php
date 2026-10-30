<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;

/**
 * A Date field turned into a DateTime one, for RetypeField: each day becomes
 * its midnight, in UTC, as a DateTime is stored. Null stays null; a value
 * following the old default follows the new one.
 */
final readonly class DateToDateTime implements FieldConverter
{
    public function convert(Captured $old): Conversion {
        if ($old->isDefault()) {
            return Conversion::default();
        }

        $value = $old->value();
        if ($value === null) {
            return Conversion::of(null);
        }

        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('DateToDateTime converts a Date stored as "%s", got %s.', ScalarValues::DATE_FORMAT, json_encode($value)));
        }

        return Conversion::of($value . ' 00:00:00');
    }
}

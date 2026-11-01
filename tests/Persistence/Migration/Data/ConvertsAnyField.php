<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;

/** A test converter's signature: it takes and gives any field, so a test about something else never trips on it. */
trait ConvertsAnyField
{
    public static function from(): FieldSignature {
        return FieldSignature::any();
    }

    public static function to(): FieldSignature {
        return FieldSignature::any();
    }
}

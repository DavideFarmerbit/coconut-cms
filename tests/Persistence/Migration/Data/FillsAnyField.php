<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;

/** A test initializer's signature: it fills any field, so a test about something else never trips on it. */
trait FillsAnyField
{
    public static function to(): FieldSignature {
        return FieldSignature::any();
    }
}

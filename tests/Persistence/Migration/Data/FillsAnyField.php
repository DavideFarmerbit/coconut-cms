<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldSignature;

/** A test initializer's signature: it fills any field, so a test about something else never trips on it. */
trait FillsAnyField
{
    public static function to(): FieldSignature {
        return FieldSignature::any();
    }
}

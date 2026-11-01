<?php

namespace XyloIsCoding\CoconutCms\Tests\Persistence\Schema\Validation;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;

/** A test field rule's signature: it fits any field, so a test about something else never trips on it. */
trait ChecksAnyField
{
    public static function fits(): FieldSignature {
        return FieldSignature::any();
    }
}

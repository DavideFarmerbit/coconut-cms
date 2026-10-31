<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry\Initializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Values;

/**
 * Gives a field added to existing entries one value, in stored form, for
 * AddField: `new ConstantValue(12)` when every existing boat is 12 meters,
 * whatever new boats default to.
 */
#[Initializer('coconut-cms:constant')]
final readonly class ConstantValue implements FieldInitializer
{
    public function __construct(
        public mixed $value,
    ) {
    }

    public function initialize(Values $values): Conversion {
        return Conversion::of($this->value);
    }

    /** Any field: the value is checked against it before anything is written. */
    public static function to(): FieldSignature {
        return FieldSignature::any();
    }
}

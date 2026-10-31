<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;

/**
 * Turns a field's old value into its new one, for RetypeField::converting().
 * Called once for every place the field lives, touched or following its old
 * default. Works in stored form both ways.
 *
 * It declares, statically, the retypes it's valid for: from() the fields it
 * takes, to() the fields it gives. A retype whose old or new field doesn't
 * match is refused, naming the mismatch; the editor offers it only where
 * both match.
 */
interface FieldConverter
{
    /**
     * $old's new value.
     * @param FieldDescriptor $to The field it becomes: what this retype gives,
     *        one of the fields to() allows.
     */
    public function convert(Captured $old, FieldDescriptor $to): Conversion;

    /** The fields it takes: what the field was. */
    public static function from(): FieldSignature;

    /** The fields it gives: what the field becomes. */
    public static function to(): FieldSignature;
}

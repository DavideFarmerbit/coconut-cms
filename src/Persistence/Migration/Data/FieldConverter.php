<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

/**
 * Turns a field's old value into its new one, for RetypeField::converting().
 * Called once for every place the field lives, touched or following its old
 * default. Works in stored form both ways.
 */
interface FieldConverter
{
    public function convert(Captured $old): Conversion;
}

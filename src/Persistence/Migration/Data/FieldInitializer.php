<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

/**
 * Gives a field added to existing entities a value other than its default,
 * for AddField. Called once for every place the field's type lives.
 *
 * It declares, statically, the fields it can fill: to(). A field it doesn't
 * match is refused, naming the mismatch; the editor offers it only where it
 * matches.
 */
interface FieldInitializer
{
    /** @param Values $values The values where the field is added, its own fields and what it owns. */
    public function initialize(Values $values): Conversion;

    /** The fields it can fill. */
    public static function to(): FieldSignature;
}

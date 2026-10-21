<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use LengthException;

/**
 * The one way to turn a value object into the string it's stored as. Holds
 * an indexable value object to its promise, so an over-long string fails
 * here, before MySQL rejects it or, without strict mode, silently cuts it.
 */
final class StorableValues
{
    /** The most characters an indexable value object's stored string may have. */
    public const MAX_INDEXABLE_LENGTH = 255;

    /** $value's toStorage(), checked against its class's indexable(). */
    public static function toStorage(StorableValue $value): string {
        $stored = $value->toStorage();

        if ($value::indexable() && mb_strlen($stored) > self::MAX_INDEXABLE_LENGTH) {
            throw new LengthException(sprintf(
                '%s stores as %d characters, but its indexable() promises at most %d.',
                $value::class,
                mb_strlen($stored),
                self::MAX_INDEXABLE_LENGTH,
            ));
        }

        return $stored;
    }
}

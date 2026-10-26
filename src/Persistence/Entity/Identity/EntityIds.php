<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Identity;

/**
 * Makes entity ids: UUIDs, generated without asking the database. Version 7,
 * so ids grow with time and new rows land at the end of the primary key
 * instead of at random places in it. Within one process every id is greater
 * than the one before, even in the same millisecond: a query sorting by id
 * gets them in creation order. Across processes, only to the millisecond.
 */
final class EntityIds
{
    /** The nil UUID: the seeded Cms row's id, which every row without a real owner points at. */
    public const NIL = '00000000-0000-0000-0000-000000000000';

    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** The largest 12-bit counter: how many ids one millisecond holds, less one. */
    private const MAX_COUNTER = 0xfff;

    /** The millisecond the last id was made in. */
    private static int $lastMillisecond = 0;

    /** The counter the last id holds, within its millisecond. */
    private static int $counter = 0;

    /** A new, unique id, greater than every id this process made before. */
    public static function generate(): string {
        // RFC 9562's counter method: the 12 bits after the version count the
        // ids of one millisecond. Each millisecond starts the counter at a
        // random point of its lower half; a full one borrows the next
        // millisecond.
        $millisecond = (int) (microtime(true) * 1000);
        if ($millisecond > self::$lastMillisecond) {
            self::$lastMillisecond = $millisecond;
            self::$counter = random_int(0, self::MAX_COUNTER >> 1);
        } elseif (++self::$counter > self::MAX_COUNTER) {
            self::$lastMillisecond++;
            self::$counter = 0;
        }

        // 48 bits of milliseconds since the epoch, the version (7), the 12-bit
        // counter, the variant (10), then 62 random bits.
        $bytes = substr(pack('J', self::$lastMillisecond), 2) . pack('n', 0x7000 | self::$counter) . random_bytes(8);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    /** Whether $id has the shape of a lowercase UUID, as generate() makes them. */
    public static function isValid(string $id): bool {
        return preg_match(self::PATTERN, $id) === 1;
    }
}

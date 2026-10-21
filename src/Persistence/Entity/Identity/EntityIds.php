<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Identity;

/**
 * Makes entity ids: UUIDs, generated without asking the database. Version 7,
 * so ids grow with time and new rows land at the end of the primary key
 * instead of at random places in it.
 */
final class EntityIds
{
    /** The nil UUID: the seeded Cms row's id, which every row without a real owner points at. */
    public const NIL = '00000000-0000-0000-0000-000000000000';

    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** A new, unique id. */
    public static function generate(): string {
        // 48 bits of milliseconds since the epoch, then 74 random bits, with
        // the version (7) and the variant (10) in their fixed places.
        $bytes = substr(pack('J', (int) (microtime(true) * 1000)), 2) . random_bytes(10);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    /** Whether $id has the shape of a lowercase UUID, as generate() makes them. */
    public static function isValid(string $id): bool {
        return preg_match(self::PATTERN, $id) === 1;
    }
}

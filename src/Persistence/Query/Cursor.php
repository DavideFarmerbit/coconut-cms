<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

use InvalidArgumentException;
use JsonException;

/**
 * Where a page ends: the last entity's sort values and its id, which breaks
 * ties. Opaque to whoever holds it: a URL-safe string. It remembers the
 * ordering it came from, so a query ordered any other way refuses it rather
 * than skipping or repeating rows.
 */
final class Cursor
{
    /**
     * @param string $ordering What the query sorts by, as ordering() writes it.
     * @param list<mixed> $values The last entity's sort values, as stored.
     */
    public static function encode(string $ordering, array $values, string $id): string {
        $json = json_encode(['ordering' => $ordering, 'values' => $values, 'id' => $id], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * The sort values and the id $cursor holds. Throws when it isn't a
     * cursor, or comes from a query sorted other than $ordering.
     * @return array{list<mixed>, string}
     */
    public static function decode(string $cursor, string $ordering): array {
        try {
            $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        if (!is_array($decoded) || !isset($decoded['ordering'], $decoded['id']) || !is_array($decoded['values'] ?? null)) {
            throw new InvalidArgumentException('That isn\'t a cursor a query gave.');
        }

        if ($decoded['ordering'] !== $ordering) {
            throw new InvalidArgumentException(sprintf('That cursor comes from a query sorted by %s, not %s.', $decoded['ordering'], $ordering));
        }

        return [array_values($decoded['values']), (string) $decoded['id']];
    }

    /**
     * How a query sorts, as a cursor records it: "price desc, name asc".
     * @param list<array{string, Order}> $orders
     */
    public static function ordering(array $orders): string {
        return implode(', ', array_map(static fn (array $order): string => $order[0] . ($order[1] === Order::Ascending ? ' asc' : ' desc'), $orders)) ?: 'id';
    }
}

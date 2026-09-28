<?php

namespace XyloIsCoding\CoconutCms\Storage\Query;

use InvalidArgumentException;
use JsonException;

/**
 * A keyset pagination position: the current sort field's value plus the id
 * tiebreaker, per ARCHITECTURE.md's cursor/keyset commitment, never OFFSET. Opaque and
 * URL-safe once encoded, an admin list view round-trips it through a query string
 * without needing to know its shape.
 */
final readonly class Cursor
{
    public function __construct(
        public mixed $sortValue,
        public string $id,
    ) {
    }

    public function encode(): string
    {
        return base64_encode(json_encode(['v' => $this->sortValue, 'id' => $this->id], JSON_THROW_ON_ERROR));
    }

    public static function decode(string $encoded): self
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Not a valid cursor.');
        }

        try {
            $data = json_decode($decoded, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Not a valid cursor.');
        }

        if (!is_array($data) || !array_key_exists('v', $data) || !isset($data['id'])) {
            throw new InvalidArgumentException('Not a valid cursor.');
        }

        return new self($data['v'], (string) $data['id']);
    }
}

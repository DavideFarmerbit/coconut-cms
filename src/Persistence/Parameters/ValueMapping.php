<?php

namespace XyloIsCoding\CoconutCms\Persistence\Parameters;

use InvalidArgumentException;

/**
 * Values mapped to others, both in stored form: what a parameter typed
 * ValueMapping takes, given as a list of pairs, `[{"from": "red", "to":
 * "crimson"}]`, so a key may be any scalar, not only a string. Each key is
 * given once. Keys match by type and value: 1 and "1" are two keys.
 */
final readonly class ValueMapping
{
    /** @param list<array{mixed, mixed}> $pairs Each key and the value it maps to, in order. */
    private function __construct(
        private array $pairs,
    ) {
    }

    /**
     * The mapping $pairs give. Throws when a key isn't a scalar or null, or
     * comes twice.
     * @param list<array{mixed, mixed}> $pairs
     */
    public static function of(array $pairs): self {
        $seen = [];
        foreach ($pairs as [$from]) {
            if ($from !== null && !is_scalar($from)) {
                throw new InvalidArgumentException(sprintf('A mapping\'s key is a scalar or null, got %s.', get_debug_type($from)));
            }

            $key = self::keyOf($from);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException(sprintf('%s is mapped twice.', json_encode($from)));
            }

            $seen[$key] = true;
        }

        return new self(array_values($pairs));
    }

    /** Whether $value is one of its keys. */
    public function has(mixed $value): bool {
        foreach ($this->pairs as [$from]) {
            if ($from === $value) {
                return true;
            }
        }

        return false;
    }

    /** What $value maps to. Throws when it isn't a key: ask has() first. */
    public function get(mixed $value): mixed {
        foreach ($this->pairs as [$from, $to]) {
            if ($from === $value) {
                return $to;
            }
        }

        throw new InvalidArgumentException(sprintf('%s isn\'t mapped.', json_encode($value)));
    }

    /** @return list<array{mixed, mixed}> Each key and the value it maps to, in order. */
    public function pairs(): array {
        return $this->pairs;
    }

    /** $value as a key only an identical value shares: type and value both. */
    private static function keyOf(mixed $value): string {
        return get_debug_type($value) . ':' . var_export($value, true);
    }
}

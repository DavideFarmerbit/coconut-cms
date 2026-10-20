<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

use InvalidArgumentException;
use Stringable;

/**
 * The path from one class down to one leaf value it owns: an Embed's fields
 * joined with ":", an OwningReference or a Collection item traversed with ".",
 * e.g. "chassis:engine:piston:diameter", "engine.piston:diameter" or
 * "engines.0.horsepower". Not a column of that class: everything past a "."
 * lives in another table. Distinct from FieldName, which names one declared
 * field on one entity's own level. A one-segment path is just the zero-prefix
 * case of this, not a FieldName in disguise.
 *
 * Built segment by segment, never by concatenating strings. Its string form is
 * what gets stored, and parses back with fromString() without needing the
 * schema: FieldName never contains a separator and is never all digits.
 */
final readonly class FieldPath implements Stringable
{
    public const EMBED = ':';
    public const TRAVERSE = '.';

    /**
     * @param list<FieldName|int> $segments A FieldName, or a Collection item's index.
     * @param list<string> $joins $joins[$i] joins $segments[$i] to $segments[$i + 1].
     */
    private function __construct(
        private array $segments,
        private array $joins,
    ) {
    }

    /** A path of one field, on the class's own level. */
    public static function of(FieldName $field): self {
        return new self([$field], []);
    }

    /** Parses what __toString() produced. */
    public static function fromString(string $path): self {
        $parts = preg_split('/([' . preg_quote(self::EMBED . self::TRAVERSE, '/') . '])/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);

        $segments = [];
        $joins = [];
        foreach ($parts as $i => $part) {
            // preg_split with DELIM_CAPTURE alternates segment, separator, segment...
            if ($i % 2 === 1) {
                $joins[] = $part;
                continue;
            }

            if ($part === '') {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid field path.', $path));
            }

            if (ctype_digit($part)) {
                if ($joins !== [] && end($joins) !== self::TRAVERSE) {
                    throw new InvalidArgumentException(sprintf(
                        '"%s" is not a valid field path: an index can only follow "%s".',
                        $path,
                        self::TRAVERSE,
                    ));
                }

                $segments[] = (int) $part;
            } else {
                $segments[] = FieldName::of($part);
            }
        }

        return new self($segments, $joins);
    }

    /** This path, then one of its Embed's own fields. */
    public function embed(FieldName $field): self {
        return new self([...$this->segments, $field], [...$this->joins, self::EMBED]);
    }

    /** This path, then a field of the object it owns, or one of its Collection items' index. */
    public function traverse(FieldName|int $segment): self {
        return new self([...$this->segments, $segment], [...$this->joins, self::TRAVERSE]);
    }

    /** This path, then $rest, joined by $join: EMBED or TRAVERSE. */
    public function append(string $join, self $rest): self {
        if ($join !== self::EMBED && $join !== self::TRAVERSE) {
            throw new InvalidArgumentException(sprintf('"%s" is not a field path separator.', $join));
        }

        if ($join === self::EMBED && is_int($rest->segments[0])) {
            throw new InvalidArgumentException(sprintf('An index can only follow "%s".', self::TRAVERSE));
        }

        return new self([...$this->segments, ...$rest->segments], [...$this->joins, $join, ...$rest->joins]);
    }

    /** Whether this path is $prefix itself or lies somewhere under it. */
    public function startsWith(self $prefix): bool {
        $length = count($prefix->segments);
        if ($length > count($this->segments)) {
            return false;
        }

        for ($i = 0; $i < $length; $i++) {
            if ((string) $this->segments[$i] !== (string) $prefix->segments[$i]) {
                return false;
            }
        }

        return array_slice($this->joins, 0, $length - 1) === $prefix->joins;
    }

    /** The first segment. */
    public function head(): FieldName|int {
        return $this->segments[0];
    }

    /** How head() joins to tail(): EMBED or TRAVERSE, or null when there's no tail. */
    public function headJoin(): ?string {
        return $this->joins[0] ?? null;
    }

    /** Everything after head(), or null when head() is all there is. */
    public function tail(): ?self {
        if (count($this->segments) === 1) {
            return null;
        }

        return new self(array_slice($this->segments, 1), array_slice($this->joins, 1));
    }

    public function __toString(): string {
        $path = (string) $this->segments[0];
        foreach ($this->joins as $i => $join) {
            $path .= $join . $this->segments[$i + 1];
        }

        return $path;
    }
}

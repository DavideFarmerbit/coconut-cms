<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * Where FieldValueResolver looks for a value: either an identifier's own
 * stored values, or values given directly, such as an instance's. Either way
 * under a path prefix: with no prefix, a key is looked up as is; under
 * "engine" joined by ".", a key "horsepower" is looked up as
 * "engine.horsepower".
 */
final readonly class ValueSource
{
    private function __construct(
        public ?string $identifier,
        public ?FieldValues $values,
        private ?FieldPath $prefix,
        private ?string $join,
    ) {
    }

    /** $identifier's own stored values, with no prefix. */
    public static function ofIdentifier(string $identifier): self {
        return new self($identifier, null, null, null);
    }

    /** $values themselves, with no prefix. */
    public static function ofValues(FieldValues $values): self {
        return new self(null, $values, null, null);
    }

    /** This source, one step further down: under $relative, joined to what follows by $join. */
    public function under(FieldPath $relative, string $join): self {
        return new self($this->identifier, $this->values, $this->pathOf($relative), $join);
    }

    /** The full path $key is held at in this source's values. */
    public function pathOf(FieldPath $key): FieldPath {
        return $this->prefix === null ? $key : $this->prefix->append($this->join, $key);
    }

    /** Whether this source reads the same values as $other, whatever the prefix. */
    public function readsSameValuesAs(self $other): bool {
        return $this->values !== null ? $this->values === $other->values : $this->identifier === $other->identifier;
    }
}

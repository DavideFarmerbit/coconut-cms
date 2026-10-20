<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPath;

/**
 * Where FieldValueResolver looks for a value: one identifier's own values,
 * under a path prefix. With no prefix, a key is looked up as is; under
 * "engine" joined by ".", a key "horsepower" is looked up as
 * "engine.horsepower".
 */
final readonly class ValueSource
{
    private function __construct(
        public string $identifier,
        private ?FieldPath $prefix,
        private ?string $join,
    ) {
    }

    /** $identifier's own values, with no prefix. */
    public static function of(string $identifier): self {
        return new self($identifier, null, null);
    }

    /** This source, one step further down: under $relative, joined to what follows by $join. */
    public function under(FieldPath $relative, string $join): self {
        return new self($this->identifier, $this->pathOf($relative), $join);
    }

    /** The full path $key is held at in this source's identifier. */
    public function pathOf(FieldPath $key): FieldPath {
        return $this->prefix === null ? $key : $this->prefix->append($this->join, $key);
    }
}

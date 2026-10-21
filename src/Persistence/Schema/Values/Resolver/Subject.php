<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * What FieldValueResolver resolves a path on: a class, and optionally values
 * of its own held in front of the class, such as an instance's. A class alone
 * is its own values' holder; an instance overrides its class the same way a
 * subclass overrides its parent.
 */
final readonly class Subject
{
    private function __construct(
        public string $class,
        private ?FieldValues $ownValues,
    ) {
    }

    /** $class itself: its own stored values come first. */
    public static function ofClass(string $class): self {
        return new self($class, null);
    }

    /** An instance of $class holding $values, checked before anything $class holds. */
    public static function ofInstance(string $class, FieldValues $values): self {
        return new self($class, $values);
    }

    /**
     * Where the subject's own values are read from: what an exclusion makes
     * it act as if it held nothing in.
     */
    public function ownSource(): ValueSource {
        return $this->ownValues !== null ? ValueSource::ofValues($this->ownValues) : ValueSource::ofClass($this->class);
    }

    /** Whether it holds values in front of its class's own. */
    public function isInstance(): bool {
        return $this->ownValues !== null;
    }
}

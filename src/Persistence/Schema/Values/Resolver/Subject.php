<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Values\Resolver;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * What FieldValueResolver resolves a path on: an entity type, by its
 * identifier, and optionally values of its own held in front of the type's,
 * such as an instance's. A type alone is its own values' holder; an instance
 * overrides its type the same way a subtype overrides its parent.
 */
final readonly class Subject
{
    private function __construct(
        public string $identifier,
        private ?FieldValues $ownValues,
    ) {
    }

    /** The type $identifier itself: its own stored values come first. */
    public static function of(string $identifier): self {
        return new self($identifier, null);
    }

    /** An instance of $identifier holding $values, checked before anything $identifier holds. */
    public static function ofInstance(string $identifier, FieldValues $values): self {
        return new self($identifier, $values);
    }

    /**
     * Where the subject's own values are read from: what an exclusion makes
     * it act as if it held nothing in.
     */
    public function ownSource(): ValueSource {
        return $this->ownValues !== null ? ValueSource::ofValues($this->ownValues) : ValueSource::ofIdentifier($this->identifier);
    }

    /** Whether it holds values in front of its type's own. */
    public function isInstance(): bool {
        return $this->ownValues !== null;
    }
}

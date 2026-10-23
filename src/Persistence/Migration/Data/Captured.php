<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

/**
 * One field's old value at one place it lives, in stored form: what a
 * converter converts. Never a PHP object: the class it belonged to may no
 * longer exist.
 */
final readonly class Captured
{
    public function __construct(
        private mixed $value,
        private bool $isDefault,
        private Values $under,
    ) {
    }

    /**
     * The value at the field itself: a scalar, a Reference's id, an owned
     * object's identifier, a Collection's item count; null for an Embed.
     */
    public function value(): mixed {
        return $this->value;
    }

    /** Whether it was following the old default rather than being touched. */
    public function isDefault(): bool {
        return $this->isDefault;
    }

    /** The values below it, keyed by path relative to the field: 'weight', 'turbo.boost', '0.size'. */
    public function under(): Values {
        return $this->under;
    }
}

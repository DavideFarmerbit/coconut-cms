<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Diff;

/** A type's parent changed: gained, lost or replaced. */
final readonly class ParentChanged implements Difference
{
    /**
     * @param string|null $old The stored parent, null when it had none.
     * @param string|null $new The parent in code, null when it has none.
     */
    public function __construct(
        private string $identifier,
        public ?string $old,
        public ?string $new,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function describe(): string {
        return sprintf('Type "%s" changed its parent from %s to %s.', $this->identifier, self::named($this->old), self::named($this->new));
    }

    private static function named(?string $parent): string {
        return $parent === null ? 'none' : sprintf('"%s"', $parent);
    }
}

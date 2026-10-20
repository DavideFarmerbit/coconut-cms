<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Field;

/**
 * How one FieldValues differs from another, path by path: what's only in the
 * new one, what's only in the old one, and what both hold with a different
 * value.
 */
final readonly class FieldValuesDiff
{
    /**
     * @param list<FieldPath> $added Held only by the new values.
     * @param list<FieldPath> $removed Held only by the old values.
     * @param list<FieldPath> $changed Held by both, with a different value.
     */
    public function __construct(
        public array $added,
        public array $removed,
        public array $changed,
    ) {
    }

    /** $new compared against $old. Values compare strictly: 10 and "10" differ. */
    public static function between(FieldValues $old, FieldValues $new): self {
        $added = [];
        $changed = [];
        foreach ($new as $path => $value) {
            if (!$old->has($path)) {
                $added[] = $path;
            } elseif ($old->get($path) !== $value) {
                $changed[] = $path;
            }
        }

        $removed = [];
        foreach ($old as $path => $value) {
            if (!$new->has($path)) {
                $removed[] = $path;
            }
        }

        return new self($added, $removed, $changed);
    }

    public function isEmpty(): bool {
        return $this->added === [] && $this->removed === [] && $this->changed === [];
    }
}

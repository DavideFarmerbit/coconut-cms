<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Breaks;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\LostAncestorReason;

/**
 * An editor-created type's chain is cut: an ancestor is gone, or is a native
 * type that no longer allows editor-created subtypes. The type falls back to
 * the nearest ancestor that's still there and still allows it, or none.
 */
final readonly class ChainCut implements EditorBreak
{
    /**
     * @param list<array{string, LostAncestorReason}> $lost Each ancestor cut
     *        off, nearest first, and why.
     * @param string|null $newParent The nearest ancestor left that allows it; null for none.
     * @param list<array{FieldName, string}> $lostFields Each field the levels
     *        cut off gave the type, with the ancestor declaring it: what it
     *        loses. A field its new chain still has, the same, isn't lost.
     */
    public function __construct(
        private string $identifier,
        public array $lost,
        public ?string $newParent,
        public array $lostFields,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function key(): string {
        return sprintf('chain-cut|%s', $this->identifier);
    }

    public function describe(): string {
        return sprintf(
            'The chain of editor-created type "%s" is cut: it loses %s, and %s.',
            $this->identifier,
            implode(', ', array_map(static fn (array $lost): string => sprintf('"%s" (%s)', $lost[0], $lost[1]->value), $this->lost)),
            $this->newParent === null ? 'would have no parent' : sprintf('would extend "%s"', $this->newParent),
        );
    }
}

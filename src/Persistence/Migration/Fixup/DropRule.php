<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;

/**
 * One validator rule removed from a type, broken or not; the rules after it
 * move up one. Stored values stay: a rule only checks them.
 */
final readonly class DropRule implements Fixup
{
    public RuleSlot $slot;

    /** @param string|null $field The field the rule checks; null for a cross-field rule. */
    public function __construct(string $type, ?string $field, int $index) {
        $this->slot = new RuleSlot($type, $field, $index);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        return $this->slot->replacedIn($schemas, null);
    }

    public function describe(): string {
        return sprintf('DropRule(%s)', $this->slot->describe());
    }
}

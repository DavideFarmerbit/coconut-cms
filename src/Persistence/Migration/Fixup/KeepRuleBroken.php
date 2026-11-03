<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Broken\BrokenRule;

/**
 * A rule that can't be built any more, kept as it was stored. It checks
 * nothing until it builds again or is fixed in the schema editor, and no
 * later migration reports it again. Loses nothing.
 */
final readonly class KeepRuleBroken implements Fixup
{
    public RuleSlot $slot;

    /** @param string|null $field The field the rule checks; null for a cross-field rule. */
    public function __construct(string $type, ?string $field, int $index) {
        $this->slot = new RuleSlot($type, $field, $index);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $rule = $this->slot->ruleIn($schemas);
        if (!$rule instanceof BrokenRule) {
            throw new InvalidFixup(sprintf('%s is not broken at this point.', $this->slot->describe()));
        }

        return $this->slot->replacedIn($schemas, $rule->kept());
    }

    public function describe(): string {
        return sprintf('KeepRuleBroken(%s)', $this->slot->describe());
    }
}

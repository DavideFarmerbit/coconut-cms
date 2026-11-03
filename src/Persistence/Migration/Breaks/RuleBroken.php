<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Breaks;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * A validator rule of an editor-created type that can't be built any more:
 * its class is gone, or its constructor no longer takes what was stored.
 * Found once: a rule kept broken isn't a break again.
 */
final readonly class RuleBroken implements EditorBreak
{
    /**
     * @param FieldName|null $field The field the rule checks; null for a cross-field rule.
     * @param int $index Its position among the field's rules, or the type's cross-field ones.
     * @param string $rule The identifier it was stored under.
     * @param string $reason Why it can't be built.
     */
    public function __construct(
        private string $identifier,
        public ?FieldName $field,
        public int $index,
        public string $rule,
        public string $reason,
    ) {
    }

    public function identifier(): string {
        return $this->identifier;
    }

    public function key(): string {
        return sprintf('rule-broken|%s|%s|%d', $this->identifier, $this->field ?? '*', $this->index);
    }

    public function describe(): string {
        $place = $this->field === null
            ? sprintf('A cross-field rule "%s" of editor-created type "%s"', $this->rule, $this->identifier)
            : sprintf('Rule "%s" on field "%s" of editor-created type "%s"', $this->rule, $this->field, $this->identifier);

        return sprintf('%s can no longer be built: %s', $place, $this->reason);
    }
}

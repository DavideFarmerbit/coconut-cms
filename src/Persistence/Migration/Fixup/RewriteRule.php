<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use InvalidArgumentException;
use ReflectionClass;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldRuleUsage;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * One validator rule of a type replaced by another, in its place: how a
 * developer fixes a rule that a constructor change broke on an
 * editor-created type. Stored values stay; the next save checks them.
 */
final readonly class RewriteRule implements Fixup
{
    public RuleSlot $slot;

    /**
     * @param string|null $field The field the rule checks; null for a cross-field rule.
     * @param FieldValidatorRule|PrototypeValidatorRule $rule The rule taking
     *        its place: a field rule in a field's slot, a cross-field rule otherwise.
     */
    public function __construct(
        string $type,
        ?string $field,
        int $index,
        public FieldValidatorRule|PrototypeValidatorRule $rule,
    ) {
        $this->slot = new RuleSlot($type, $field, $index);
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $this->slot->ruleIn($schemas);
        if (($this->slot->field === null) !== ($this->rule instanceof PrototypeValidatorRule)) {
            throw new InvalidFixup(sprintf('%s takes a %s rule.', $this->slot->describe(), $this->slot->field === null ? 'cross-field' : 'field'));
        }

        try {
            if ($this->rule instanceof FieldValidatorRule) {
                FieldRuleUsage::check($this->rule, $schemas->field($this->slot->type, FieldName::of($this->slot->field)));
            } else {
                $this->rule->checkUsage($schemas->get($this->slot->type)->fields);
            }
        } catch (InvalidArgumentException $refused) {
            throw new InvalidFixup(sprintf('%s can\'t take it: %s', $this->slot->describe(), $refused->getMessage()));
        }

        return $this->slot->replacedIn($schemas, $this->rule);
    }

    public function describe(): string {
        return sprintf('RewriteRule(%s, %s)', $this->slot->describe(), (new ReflectionClass($this->rule))->getShortName());
    }
}

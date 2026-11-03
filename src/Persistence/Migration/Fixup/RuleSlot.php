<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * Where one validator rule sits on a type: a field's rule by its position
 * among the field's rules, or a cross-field rule by its position among the
 * type's. How a fixup on a single rule names it.
 */
final readonly class RuleSlot
{
    /** @param string|null $field The field the rule checks; null for a cross-field rule. */
    public function __construct(
        public string $type,
        public ?string $field,
        public int $index,
    ) {
    }

    /**
     * The rule in this slot.
     * @throws InvalidFixup When there's none at this point.
     */
    public function ruleIn(SchemaState $schemas): FieldValidatorRule|PrototypeValidatorRule {
        $rules = $this->rulesIn($schemas);
        if (!isset($rules[$this->index])) {
            throw new InvalidFixup(sprintf('%s holds no rule at this point.', $this->describe()));
        }

        return $rules[$this->index];
    }

    /**
     * $schemas with this slot's rule replaced by $rule, or removed when
     * $rule is null; the rules after it move up one.
     * @throws InvalidFixup When there's none at this point.
     */
    public function replacedIn(SchemaState $schemas, FieldValidatorRule|PrototypeValidatorRule|null $rule): SchemaState {
        $this->ruleIn($schemas);
        $rules = $this->rulesIn($schemas);
        array_splice($rules, $this->index, 1, $rule === null ? [] : [$rule]);

        $schema = $schemas->get($this->type);
        if ($this->field === null) {
            return $schemas->with($schema->withPrototypeValidators($rules));
        }

        $name = FieldName::of($this->field);
        $validators = $rules === [] ? $schema->fieldValidators->without($name) : $schema->fieldValidators->with($name, $rules);

        return $schemas->with($schema->withFieldValidators($validators));
    }

    /** How a fixup names it: "rule 0 of default:car.price", "cross-field rule 1 of default:car". */
    public function describe(): string {
        return $this->field === null
            ? sprintf('cross-field rule %d of %s', $this->index, $this->type)
            : sprintf('rule %d of %s.%s', $this->index, $this->type, $this->field);
    }

    /** @return list<FieldValidatorRule|PrototypeValidatorRule> The slot's list, as it is in $schemas. */
    private function rulesIn(SchemaState $schemas): array {
        $schema = $schemas->get($this->type);

        return array_values($this->field === null ? $schema->prototypeValidators : $schema->fieldValidators->get(FieldName::of($this->field)));
    }
}

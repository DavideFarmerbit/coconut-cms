<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldRuleUsage;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;

/**
 * An editor-created type's own field given its full list of rules, in order,
 * replacing the ones it had; none clears them. Each must fit the field. Only
 * the definition changes: content breaking a rule is caught on its next save.
 */
final readonly class SetFieldRules implements SchemaOperation
{
    public FieldName $field;

    /** @var list<FieldValidatorRule> */
    public array $rules;

    public function __construct(
        public string $type,
        string $field,
        FieldValidatorRule ...$rules,
    ) {
        $this->field = FieldName::of($field);
        $this->rules = array_values($rules);
    }

    public function applyTo(SchemaDraft $draft): void {
        $descriptor = $draft->ownField($this->type, $this->field);
        foreach ($this->rules as $index => $rule) {
            try {
                FieldRuleUsage::check($rule, $descriptor);
            } catch (InvalidArgumentException $refused) {
                throw new InvalidOperation(sprintf('Rule %d: %s', $index, $refused->getMessage()), 0, $refused);
            }
        }

        $schema = $draft->editorType($this->type);
        $draft->replace($schema->withFieldValidators($this->rules === []
            ? $schema->fieldValidators->without($this->field)
            : $schema->fieldValidators->with($this->field, $this->rules)));
    }

    public function describe(): string {
        return sprintf('SetFieldRules(%s.%s, %d)', $this->type, $this->field, count($this->rules));
    }
}

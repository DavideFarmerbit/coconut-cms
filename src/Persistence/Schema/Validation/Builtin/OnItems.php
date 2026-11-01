<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin;

use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\FieldRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldRuleUsage;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

/**
 * Runs its rules on each item of a Collection: `new OnItems(new MaxLength(4))`
 * keeps every tag at most 4 characters. Each rule must fit the items, the way
 * it would fit a field holding one. An item's error lands on its own path,
 * "tags.1".
 */
#[FieldRule('coconut-cms:on-items')]
final readonly class OnItems implements FieldValidatorRule
{
    /** @var list<FieldValidatorRule> What each item is checked with. */
    public array $rules;

    public function __construct(FieldValidatorRule ...$rules) {
        $this->rules = array_values($rules);
    }

    /** Any Collection. */
    public static function fits(): FieldSignature {
        return FieldSignature::collection();
    }

    /** Checks each of its rules against the items. */
    public function checkUsage(FieldDescriptor $field): void {
        foreach ($this->rules as $rule) {
            FieldRuleUsage::check($rule, $field->itemDescriptor());
        }
    }

    /** Every rule on every item, each error under its item's index. */
    public function validate(mixed $value): ValidationResult {
        $results = [];
        foreach ($value as $index => $item) {
            foreach ($this->rules as $rule) {
                $results[] = $rule->validate($item)->atItem($index);
            }
        }

        return ValidationResult::combined(...$results);
    }
}

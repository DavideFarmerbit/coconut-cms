<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;
use XyloIsCoding\CoconutCms\Persistence\Schema\FieldValidatorRule;

/**
 * Attaches this field's own composable list of validation rules, each already a
 * constructed instance so a parameterized rule can carry its own config.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class FieldValidator
{
    /** @var FieldValidatorRule[] */
    public array $rules;

    public function __construct(FieldValidatorRule ...$rules) {
        $this->rules = $rules;
    }
}

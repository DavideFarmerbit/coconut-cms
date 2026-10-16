<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class;

use Attribute;
use XyloIsCoding\CoconutCms\Persistence\Schema\PrototypeValidatorRule;

/**
 * Attaches this shape's own composable list of cross-field validation rules, each
 * already a constructed instance so a parameterized rule can carry its own config.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PrototypeValidator
{
    /** @var PrototypeValidatorRule[] */
    public array $rules;

    public function __construct(PrototypeValidatorRule ...$rules) {
        $this->rules = $rules;
    }
}

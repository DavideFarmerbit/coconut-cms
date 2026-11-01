<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;

/**
 * Whether a field rule may sit on a field: what reading a class's
 * #[FieldValidator] and an editor save adding a rule both check.
 */
final class FieldRuleUsage
{
    /**
     * Throws unless $field matches $rule's fits(), then lets the rule check
     * its own configuration against $field.
     * @throws InvalidArgumentException
     */
    public static function check(FieldValidatorRule $rule, FieldDescriptor $field): void {
        $fits = $rule::fits();
        if (!$fits->matches($field)) {
            throw new InvalidArgumentException(sprintf('%s checks %s, and field "%s" is not one.', $rule::class, $fits->describe(), $field->name()));
        }

        $rule->checkUsage($field);
    }
}

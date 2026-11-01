<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Builtin;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\FieldRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

/**
 * Text matching a regular expression: `new Pattern('^[A-Z]{2}\d{4}$')`. The
 * expression has no delimiters and no flags; it reads Unicode text. It isn't
 * anchored: use ^ and $ to match the whole text. Fits a String or Text field;
 * OnItems puts it on a Collection's items. Null passes: whether a field may be
 * empty is its nullability's say.
 */
#[FieldRule('coconut-cms:pattern')]
final readonly class Pattern implements FieldValidatorRule
{
    /** @param string $regex The expression, without delimiters. Throws when it doesn't compile. */
    public function __construct(
        public string $regex,
    ) {
        if (@preg_match($this->compiled(), '') === false) {
            throw new InvalidArgumentException(sprintf('"%s" is no valid regular expression: %s.', $regex, preg_last_error_msg()));
        }
    }

    /** A String or a Text. */
    public static function fits(): FieldSignature {
        return FieldSignature::text();
    }

    /** Nothing to check: any expression fits any text. */
    public function checkUsage(FieldDescriptor $field): void {
    }

    /** Valid when $value matches. */
    public function validate(mixed $value): ValidationResult {
        return $value === null || preg_match($this->compiled(), $value) === 1
            ? ValidationResult::valid()
            : ValidationResult::invalid(sprintf('must match %s', $this->regex));
    }

    /** $regex as preg_match() takes it: delimited, in Unicode mode. A "~" in it is escaped, as the delimiter. */
    private function compiled(): string {
        return '~' . str_replace('~', '\~', $this->regex) . '~u';
    }
}

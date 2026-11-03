<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Broken;

use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldSignature;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

/** A field's stored rule that can't be built any more: it fits any field and checks nothing. */
final readonly class BrokenFieldRule implements FieldValidatorRule, BrokenRule
{
    /** @param array<string, mixed> $entry As it was stored. */
    public function __construct(
        private array $entry,
        private string $reason,
        private bool $kept,
    ) {
    }

    public static function fits(): FieldSignature {
        return FieldSignature::any();
    }

    public function checkUsage(FieldDescriptor $field): void {
    }

    public function validate(mixed $value): ValidationResult {
        return ValidationResult::valid();
    }

    public function entry(): array {
        return $this->entry;
    }

    public function reason(): string {
        return $this->reason;
    }

    public function wasKept(): bool {
        return $this->kept;
    }

    public function kept(): static {
        return new self($this->entry, $this->reason, true);
    }
}

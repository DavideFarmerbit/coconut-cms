<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Broken;

use XyloIsCoding\CoconutCms\Persistence\Entity\EntityView;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;

/** A type's stored cross-field rule that can't be built any more: it checks nothing. */
final readonly class BrokenPrototypeRule implements PrototypeValidatorRule, BrokenRule
{
    /** @param array<string, mixed> $entry As it was stored. */
    public function __construct(
        private array $entry,
        private string $reason,
        private bool $kept,
    ) {
    }

    public function checkUsage(FieldDescriptorCollection $fields): void {
    }

    public function validate(EntityView $entity): ValidationResult {
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

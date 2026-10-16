<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use LogicException;

/**
 * The outcome of one FieldValidatorRule or PrototypeValidatorRule check.
 */
final readonly class ValidationResult
{
    private function __construct(
        private bool $valid,
        private ?string $error,
    ) {
    }

    public static function valid(): self {
        return new self(true, null);
    }

    /**
     * @param string $error The reason validation failed.
     */
    public static function invalid(string $error): self {
        return new self(false, $error);
    }

    public function isValid(): bool {
        return $this->valid;
    }

    /** Valid only when isValid() is false. */
    public function error(): string {
        if ($this->valid) {
            throw new LogicException('A valid ValidationResult has no error.');
        }

        return $this->error;
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Storage\Validator;

use XyloIsCoding\CoconutCms\Storage\FieldValidator;

/** Rejects strings longer than the given length. Non-string values always pass. */
final readonly class MaxLengthValidator implements FieldValidator
{
    public function __construct(
        public int $maxLength,
    ) {
    }

    public function validate(mixed $value): bool
    {
        return !is_string($value) || strlen($value) <= $this->maxLength;
    }

    public function describe(): array
    {
        return ['type' => 'maxLength', 'value' => $this->maxLength];
    }
}

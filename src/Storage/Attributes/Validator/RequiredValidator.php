<?php

namespace XyloIsCoding\CoconutCms\Storage\Attributes\Validator;

use XyloIsCoding\CoconutCms\Storage\Attributes\FieldValidator;

/** Rejects null and empty strings. Everything else passes. */
final readonly class RequiredValidator implements FieldValidator
{
    public function validate(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    public function describe(): array
    {
        return ['type' => 'required'];
    }
}

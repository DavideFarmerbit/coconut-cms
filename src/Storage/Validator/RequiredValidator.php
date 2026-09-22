<?php

namespace XyloIsCoding\CoconutCms\Storage\Validator;

use XyloIsCoding\CoconutCms\Storage\FieldValidator;

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

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property;

use Attribute;

/**
 * Names the method an edit by path calls to change this field, instead of
 * setting the property: `#[Scalar(ScalarType::String), Setter('rename')]`.
 * Only the class declaring the field names it. The method takes the new
 * value in PHP form; it may change it, change other fields with it, or throw
 * EditRefused. It's called from the declaring class's scope, with PHP's
 * rules: a subclass overriding a public or protected setter gets its own
 * version run; a private one always runs as written.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Setter
{
    /** @param string $method The method's name, on the declaring class. */
    public function __construct(
        public string $method,
    ) {
    }
}

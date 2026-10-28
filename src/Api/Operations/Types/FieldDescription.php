<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Types;

/** One field of a type, and what the caller may do with its value. */
final readonly class FieldDescription
{
    public function __construct(
        public bool $read,
        public bool $write,
        public ValueDescription $value,
    ) {
    }
}

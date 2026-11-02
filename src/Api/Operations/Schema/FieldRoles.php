<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

/** The roles a field asks to be read and written, as setFieldPermission takes them; null leaves it open. */
final readonly class FieldRoles
{
    public function __construct(
        public ?string $read,
        public ?string $write,
    ) {
    }
}

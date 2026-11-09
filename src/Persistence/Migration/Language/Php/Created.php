<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php;

/**
 * An owned object a step made with `new`: its type and the fields it set.
 * The engine gives it its id; a field left out follows its default.
 */
final readonly class Created
{
    /** @param array<string, mixed> $fields In stored form, by name. */
    public function __construct(
        public string $type,
        public array $fields,
    ) {
    }
}

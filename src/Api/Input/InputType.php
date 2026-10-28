<?php

namespace XyloIsCoding\CoconutCms\Api\Input;

/** The type one input parameter, or one list item, takes: what InputReader checks a value against. */
final readonly class InputType
{
    /**
     * @param class-string|null $class The enum or OperationInput class, for those kinds.
     * @param InputType|null $item Each item's type, for a list.
     */
    public function __construct(
        public InputKind $kind,
        public bool $nullable = false,
        public ?string $class = null,
        public ?InputType $item = null,
    ) {
    }
}

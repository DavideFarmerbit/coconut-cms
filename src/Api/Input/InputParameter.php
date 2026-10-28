<?php

namespace XyloIsCoding\CoconutCms\Api\Input;

/** One constructor parameter of an OperationInput: what an input key must hold. */
final readonly class InputParameter
{
    /**
     * @param bool $optional Whether it has a default, used when the key is missing.
     * @param mixed $default That default; null when it has none.
     */
    public function __construct(
        public string $name,
        public InputType $type,
        public bool $optional,
        public mixed $default,
    ) {
    }
}

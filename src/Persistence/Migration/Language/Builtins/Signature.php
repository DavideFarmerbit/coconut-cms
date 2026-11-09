<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Builtins;

use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Type;

/**
 * What a built-in call is, once checked: the type it gives, and the type
 * each argument is converted to before the call (an int becoming a decimal
 * for `price + 1`).
 */
final readonly class Signature
{
    /** @param list<Type|null> $parameters Null leaves the argument as it is: a lambda, a mode. */
    public function __construct(
        public Type $result,
        public array $parameters,
    ) {
    }
}

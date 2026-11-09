<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Builtins;

use Closure;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree\Expr;
use XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types\Type;

/**
 * One built-in function: how it's typed and what it is in PHP. Its meaning
 * is written once, in Runtime, and stays: a built-in whose behaviour must
 * change gets a new name.
 */
final readonly class Builtin
{
    /**
     * @param string $usage How it's called, for errors: "trim(text)".
     * @param Closure(list<Type>, list<Expr>, ?Type): Signature $check Throws TypeProblem.
     * @param Closure(list<string>, list<Type>, Type): string $php PHP source of a call, from its arguments' source.
     * @param bool $sharesType A number literal or a bare name among its arguments takes the others' type: `price + 1`, `status == Draft`.
     * @param bool $lambda Its second argument is a lambda run on the first one's items.
     */
    public function __construct(
        public string $name,
        public string $usage,
        public Closure $check,
        public Closure $php,
        public bool $sharesType = false,
        public bool $lambda = false,
    ) {
    }
}

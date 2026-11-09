<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Php;

use Closure;

/**
 * A data step compiled to PHP: run it on one place its type lives. Its
 * source is kept, to read when something's wrong.
 */
final readonly class CompiledStep
{
    /** @param Closure(array<string, mixed>, array<string, true>): array<string, mixed> $run */
    public function __construct(
        public string $source,
        private Closure $run,
    ) {
    }

    /**
     * What the step sets, by path from where it runs ("price.amount"):
     * values in stored form, FollowsDefault, or Created for a new owned
     * object. Throws StepFailed when the step fails on these values.
     * @param array<string, mixed> $old The old values, by field: records and owned objects as arrays, lists as lists.
     * @param array<string, true> $defaults The old paths whose values follow their default, "price.amount".
     * @return array<string, mixed>
     */
    public function run(array $old, array $defaults = []): array {
        return ($this->run)($old, $defaults);
    }
}

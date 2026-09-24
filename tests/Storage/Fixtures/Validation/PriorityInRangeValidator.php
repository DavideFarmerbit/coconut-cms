<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation;

use XyloIsCoding\CoconutCms\Storage\PrototypeValidator;

/** A second, independent cross-field rule, to prove multiple chain levels' own validators all run. */
final readonly class PriorityInRangeValidator implements PrototypeValidator
{
    public function validate(array $values): bool
    {
        return $values['priority'] >= 1 && $values['priority'] <= 5;
    }

    public function describe(): array
    {
        return ['type' => 'priorityInRange'];
    }
}

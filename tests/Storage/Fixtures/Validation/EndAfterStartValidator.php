<?php

namespace XyloIsCoding\CoconutCms\Tests\Storage\Fixtures\Validation;

use XyloIsCoding\CoconutCms\Storage\PrototypeValidator;

/** A cross-field rule: endDay must come after startDay. */
final readonly class EndAfterStartValidator implements PrototypeValidator
{
    public function validate(array $values): bool
    {
        return $values['endDay'] > $values['startDay'];
    }

    public function describe(): array
    {
        return ['type' => 'endAfterStart'];
    }
}

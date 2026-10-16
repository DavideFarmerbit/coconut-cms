<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class;

use Attribute;
use InvalidArgumentException;

/**
 * Declares that this shape's own named fields, together, must be unique. A shape may
 * carry more than one such group.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Unique
{
    /** @var string[] */
    public array $fields;

    /**
     * @param string ...$fields One or more of this shape's own field names, together
     *        forming one group.
     */
    public function __construct(string ...$fields) {
        if ($fields === []) {
            throw new InvalidArgumentException('Unique requires at least one field.');
        }

        $this->fields = $fields;
    }
}

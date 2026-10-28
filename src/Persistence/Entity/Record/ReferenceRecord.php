<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Record;

/**
 * What a Reference holds, in an EntityRecord: its target's concrete type and
 * id, enough to open it. Given even when the target's type can't be read:
 * reading it then is refused.
 */
final readonly class ReferenceRecord
{
    public function __construct(
        public string $type,
        public string $id,
    ) {
    }
}

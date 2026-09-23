<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

/** A batch of entity changes to flush atomically, in whatever order keeps every dependency satisfied. */
final readonly class Changeset
{
    /** @param EntityChange[] $changes */
    public function __construct(
        public array $changes,
    ) {
    }
}

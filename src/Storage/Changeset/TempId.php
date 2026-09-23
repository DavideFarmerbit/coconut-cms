<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

/**
 * A placeholder id for an entity being created in the same Changeset it's referenced
 * from, e.g. "attach this new Tag to this Product" before the Tag has a real id yet.
 * ChangesetSorter scans for these to derive write order automatically.
 */
final readonly class TempId
{
    public function __construct(
        public string $id,
    ) {
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\NoType;

/**
 * What broke on an editor-created type, kept on its schema next to its
 * fields until an admin decides: a field set aside with its values
 * (BrokenField), or a level cut out of its chain (LostAncestor). Never a
 * field: nothing that reads fields meets one.
 */
interface NoTypeRecord
{
    /** Its identity, generated when the break happened. */
    public function id(): string;
}

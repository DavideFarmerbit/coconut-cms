<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;

/**
 * What no shorthand covers: splitting a field, merging several into one,
 * deriving one from others. Runs, for Transform, at every place its type
 * lives, over the values rooted there.
 */
interface Transformation
{
    /**
     * The schema fixups it amounts to: splitting `name` drops `name` and adds
     * `firstName` and `lastName`. Run before its values move.
     * @return list<Fixup>
     */
    public function declares(): array;

    public function transform(MigratingValues $values): void;
}

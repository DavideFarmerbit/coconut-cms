<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

/**
 * Fills the levels a type gains when it's reparented, for Reparent. Called
 * once for every place an entity of the type or a subclass lives. May set
 * fields of the levels gained, and only those; what it leaves alone follows
 * its default.
 */
interface LevelInitializer
{
    public function initialize(MigratingValues $values): void;
}

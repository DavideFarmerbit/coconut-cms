<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\Entity;

/**
 * The one seeded entity every row without a real owner points at, through
 * the nil UUID. An ordinary native class otherwise, with a table of its own.
 * Declares no fields yet: CMS-wide singleton data would go in its own Owned
 * fields, if that need shows up.
 */
#[Entity(identifier: self::IDENTIFIER, table: 'cms')]
final class Cms
{
    /** What its entities row and schema name it by. */
    public const IDENTIFIER = 'coconut-cms:cms';
}

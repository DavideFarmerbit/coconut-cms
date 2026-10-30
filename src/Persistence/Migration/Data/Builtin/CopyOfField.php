<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Conversion;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Values;

/**
 * Gives a field added to existing entries the value another field holds
 * there, for AddField: `new CopyOfField('name')` starts a new `displayName`
 * at each entry's name. $path is relative to the entry: "name", or
 * "engine.power" into what it owns. An entry holding nothing there follows
 * the new field's default.
 */
final readonly class CopyOfField implements FieldInitializer
{
    public function __construct(
        public string $path,
    ) {
    }

    public function initialize(Values $values): Conversion {
        return $values->has($this->path) ? Conversion::of($values->get($this->path)) : Conversion::default();
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * One type as the schema editor starts from it: its schema, whether the
 * code or the editor made it, and the defaults it sets itself, by path,
 * in stored form: a path left out follows its ancestors.
 */
final readonly class SchemaDefinition
{
    public function __construct(
        public EntitySchema $schema,
        public SchemaSource $source,
        public FieldValues $ownDefaults,
    ) {
    }
}

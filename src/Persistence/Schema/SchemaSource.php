<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

/**
 * Where an entity type's schema comes from: a native class, or the editor. A
 * native migration only compares native schemas with the code; an editor-created
 * type missing from the code is never a removed native one.
 */
enum SchemaSource: string
{
    case Native = 'native';
    case Editor = 'editor';
}

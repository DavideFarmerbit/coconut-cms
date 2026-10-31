<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use RuntimeException;

/**
 * An actor without the project's schema-editor role saving, or previewing,
 * changes to editor-created types. Nothing was read or changed.
 */
final class SchemaEditingDenied extends RuntimeException
{
    public function __construct() {
        parent::__construct('Its actor may not change editor-created types.');
    }
}

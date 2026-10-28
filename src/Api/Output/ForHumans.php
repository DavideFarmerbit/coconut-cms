<?php

namespace XyloIsCoding\CoconutCms\Api\Output;

/**
 * A result the command line prints as a table rather than JSON. JSON is
 * still what HTTP sends, and what `--output=json` prints.
 */
interface ForHumans
{
    /** The result as a table. */
    public function table(): TextTable;
}

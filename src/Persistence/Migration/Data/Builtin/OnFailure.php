<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

/** What a built-in converter does with a value it can't convert. */
enum OnFailure: string
{
    /** The entry follows the field's new default. */
    case Default = 'default';

    /** The whole migration fails, naming the value. */
    case Cancel = 'cancel';
}

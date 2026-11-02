<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Builtin;

/** What TextToText does with text longer than a String holds. */
enum OnTooLong: string
{
    /** Cut to the most characters a String holds. */
    case Truncate = 'truncate';

    /** The entry follows the field's new default. */
    case Default = 'default';

    /** The whole migration fails, naming the value. */
    case Cancel = 'cancel';
}

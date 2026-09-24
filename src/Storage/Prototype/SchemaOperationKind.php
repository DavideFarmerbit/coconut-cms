<?php

namespace XyloIsCoding\CoconutCms\Storage\Prototype;

/** What a logged SchemaOperation did. Only DropColumn carries real data-loss risk, so only it needs a snapshot. */
enum SchemaOperationKind
{
    case AddColumn;
    case DropColumn;
}

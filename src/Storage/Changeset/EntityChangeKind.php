<?php

namespace XyloIsCoding\CoconutCms\Storage\Changeset;

/** What kind of change an EntityChange represents. */
enum EntityChangeKind
{
    case Create;
    case Update;
    case Delete;
}

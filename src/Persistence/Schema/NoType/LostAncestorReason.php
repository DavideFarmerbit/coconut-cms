<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\NoType;

/** Why a level was cut out of an editor-created type's chain. */
enum LostAncestorReason: string
{
    /** The ancestor is gone. */
    case Dropped = 'dropped';

    /** The ancestor is a native type that no longer allows editor-created subtypes. */
    case NotExtensible = 'not_extensible';
}

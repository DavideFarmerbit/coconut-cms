<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\NoType;

/** Why a field of an editor-created type was set aside. */
enum BrokenFieldReason: string
{
    /** The type it declares as its target is gone. */
    case TargetGone = 'target_gone';

    /** The ancestor declaring it was cut out of the chain. */
    case ChainCut = 'chain_cut';

    /** A field it now inherits has its name. */
    case NameClash = 'name_clash';
}

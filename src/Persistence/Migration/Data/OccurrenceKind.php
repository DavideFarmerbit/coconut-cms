<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data;

/** How an aggregate holds an object. */
enum OccurrenceKind
{
    /** The aggregate's own root entity. */
    case Root;

    /** An owned entity, in a singular field or as a Collection item: its slot holds its identifier. */
    case Owned;

    /** An Embed, singular or as a Collection item: flattened into its holder, no identifier of its own. */
    case Embedded;
}

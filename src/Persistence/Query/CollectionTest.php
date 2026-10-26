<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

/** What a CollectionCondition asks of a Collection. */
enum CollectionTest
{
    /** At least one item meets the Item. */
    case Has;

    /** No item meets the Item. */
    case DoesntHave;

    /** The Collection's length meets a Condition. */
    case Count;
}

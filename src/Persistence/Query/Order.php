<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

/** Which way orderBy() sorts. A null sorts lowest: first ascending, last descending. */
enum Order
{
    case Ascending;
    case Descending;
}

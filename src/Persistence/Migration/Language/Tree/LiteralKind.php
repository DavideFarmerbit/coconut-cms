<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Tree;

/** What a literal holds. A number gets its type from where it's used. */
enum LiteralKind: string
{
    case Null = 'null';
    case Bool = 'bool';
    case Text = 'text';

    /** Digits, with a point or not, kept as written: `12`, `12.50`. */
    case Number = 'number';

    /** `@2024-01-31`, held as "2024-01-31". */
    case Date = 'date';

    /** `@2024-01-31T10:00:00Z`, held in UTC as "2024-01-31 10:00:00". */
    case DateTime = 'datetime';
}

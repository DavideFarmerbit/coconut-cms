<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Syntax;

/** What a token is. Keywords are words: the parser decides by where they are. */
enum TokenKind
{
    /** A word, `price`, or a quoted name, `` `my field` ``. */
    case Word;

    /** Digits, with a point or not. */
    case Number;

    /** A quoted text, its escapes resolved. */
    case Text;

    /** `@2024-01-31`, as "2024-01-31". */
    case Date;

    /** `@2024-01-31T10:00:00Z`, in UTC as "2024-01-31 10:00:00". */
    case DateTime;

    /** An operator or a bracket: `{`, `?.`, `=>`. */
    case Symbol;

    case End;
}

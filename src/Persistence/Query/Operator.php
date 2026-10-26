<?php

namespace XyloIsCoding\CoconutCms\Persistence\Query;

/** How a Condition compares a field's value. Built through Is, never by hand. */
enum Operator
{
    case Equal;
    case NotEqual;
    case Greater;
    case GreaterOrEqual;
    case Less;
    case LessOrEqual;
    case In;
    case IsNull;
    case IsNotNull;
    case Like;

    /** Whether it compares by order: only a field whose values have one takes it. */
    public function isOrdering(): bool {
        return in_array($this, [self::Greater, self::GreaterOrEqual, self::Less, self::LessOrEqual], true);
    }
}

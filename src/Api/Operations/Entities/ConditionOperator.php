<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Entities;

use XyloIsCoding\CoconutCms\Persistence\Query\Operator;

/** How a condition of entities.query compares, as its input names it. */
enum ConditionOperator: string
{
    case Equal = 'equal';
    case NotEqual = 'notEqual';
    case Greater = 'greater';
    case GreaterOrEqual = 'greaterOrEqual';
    case Less = 'less';
    case LessOrEqual = 'lessOrEqual';
    case In = 'in';
    case IsNull = 'isNull';
    case IsNotNull = 'isNotNull';
    case Like = 'like';

    /** The query's own operator. */
    public function operator(): Operator {
        return match ($this) {
            self::Equal => Operator::Equal,
            self::NotEqual => Operator::NotEqual,
            self::Greater => Operator::Greater,
            self::GreaterOrEqual => Operator::GreaterOrEqual,
            self::Less => Operator::Less,
            self::LessOrEqual => Operator::LessOrEqual,
            self::In => Operator::In,
            self::IsNull => Operator::IsNull,
            self::IsNotNull => Operator::IsNotNull,
            self::Like => Operator::Like,
        };
    }

    /** Null when $count values suit it; otherwise what it takes, for an error message. */
    public function wrongCount(int $count): ?string {
        return match ($this) {
            self::IsNull, self::IsNotNull => $count === 0 ? null : 'Takes no value.',
            self::In => $count > 0 ? null : 'Takes at least one value.',
            default => $count === 1 ? null : 'Takes exactly one value.',
        };
    }
}

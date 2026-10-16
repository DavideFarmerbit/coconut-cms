<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

/**
 * The primitive types a Scalar field can hold. Every case has a framework-provided
 * conversion to and from the database. Decimal and Enum need their own conversion logic
 * (a BigDecimal, a backed enum instance) but never a developer-supplied custom class,
 * which is what keeps them Scalar instead of ValueObject.
 */
enum ScalarType: string
{
    case String = 'string';
    case Text = 'text';
    case Int = 'int';
    case Bool = 'bool';
    case Float = 'float';
    case Date = 'date';
    case DateTime = 'date_time';
    case Decimal = 'decimal';
    case Enum = 'enum';
}

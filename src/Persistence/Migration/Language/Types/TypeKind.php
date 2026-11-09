<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Language\Types;

/** What a Type is: a field kind, or one of the types only expressions have. */
enum TypeKind: string
{
    /** A scalar value: text, a number, a date, an enum's backing value. */
    case Scalar = 'scalar';

    /** A Collection's items. */
    case List = 'list';

    /** An Embed's fields, as a record. */
    case Embed = 'embed';

    /** An owned object: read from old, or made with `new`. */
    case Owned = 'owns';

    /** The id of another entity. */
    case Reference = 'ref';

    /** The identifier of a type, a subtype of its base. */
    case TypeReference = 'type';

    /** A value object, copied whole. */
    case Value = 'value';

    /** The literal null, before it meets the type it fills. */
    case Null = 'null';

    /** `default`: the field follows its new default. Fills any field. */
    case Default = 'default';

    /** What never gives a value: `fail(…)`. Fits anywhere. */
    case Never = 'never';
}

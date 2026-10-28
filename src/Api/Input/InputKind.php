<?php

namespace XyloIsCoding\CoconutCms\Api\Input;

/** What kind of value one input parameter, or one list item, takes. */
enum InputKind
{
    case String;
    case Int;
    case Float;
    case Bool;
    /** Any JSON value, read as is. */
    case Mixed;
    /** A backed enum's value. */
    case Enum;
    /** A nested OperationInput, as a JSON object. */
    case Input;
    /** A JSON array, each item of one InputType. */
    case List;
    /** A JSON object with keys of its own choosing, each value of one InputType. */
    case Map;
}

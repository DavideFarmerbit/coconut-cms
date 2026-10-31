<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

/** What a registered converter's or initializer's constructor parameter takes. */
enum ParameterType
{
    case String;
    case Int;
    /** An int is taken too. */
    case Float;
    case Bool;
    /** Any value, as given: a value in stored form. */
    case Mixed;
    /** A backed enum, by its value. */
    case Enum;
    /** A string holding an enum class, given as the enum's registered identifier. */
    case RegisteredEnum;
}

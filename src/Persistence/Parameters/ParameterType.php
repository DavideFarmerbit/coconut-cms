<?php

namespace XyloIsCoding\CoconutCms\Persistence\Parameters;

/** What a registered converter's, initializer's or validator rule's constructor parameter takes. */
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
    /**
     * Field rules, as a variadic `FieldValidatorRule ...$rules`: each given as
     * `{"rule": identifier, "config": its own parameters}`.
     */
    case FieldRules;
    /**
     * Values mapped to others, as a ValueMapping: given as a list of
     * `{"from": value, "to": value}`, each in stored form.
     */
    case Map;
}

<?php

namespace XyloIsCoding\CoconutCms\Storage\Field;

/**
 * The kind of value a FieldDescriptor describes. Decides how the field is stored and
 * how it's hydrated, independent of whether the field came from a native class or an
 * editor-assembled shape.
 */
enum FieldKind
{
    /** A plain string value. */
    case String;

    /** A plain integer value. */
    case Int;

    /** A plain float value. */
    case Float;

    /** A plain boolean value. */
    case Bool;

    /** One of a fixed set of options. See FieldDescriptor::$choiceOptions. */
    case Choice;

    /** A value object with no identity of its own. Always inlined into its owner's storage. */
    case EmbeddedValueObject;

    /** A reference to another entity, which has its own identity and storage. */
    case EntityReference;

    /** A repeated list of values of FieldDescriptor::$collectionItemKind. */
    case Collection;
}

<?php

namespace XyloIsCoding\CoconutCms\Storage\Field;

/**
 * The kind of value a FieldDescriptor describes. Decides how the field is stored and
 * how it's hydrated, independent of whether the field came from a native class or an
 * editor-assembled shape. String-backed so PrototypeRegistry can persist and reload a
 * kind for an editor-created field without a separate serialization scheme.
 */
enum FieldKind: string
{
    /** A plain string value. */
    case String = 'String';

    /** A plain integer value. */
    case Int = 'Int';

    /** A plain float value. */
    case Float = 'Float';

    /** A plain boolean value. */
    case Bool = 'Bool';

    /** One of a fixed set of options. See FieldDescriptor::$choiceOptions. */
    case Choice = 'Choice';

    /** A value object with no identity of its own. Always inlined into its owner's storage. */
    case EmbeddedValueObject = 'EmbeddedValueObject';

    /** A reference to another entity, which has its own identity and storage. */
    case EntityReference = 'EntityReference';

    /** A repeated list of values of FieldDescriptor::$collectionItemKind. */
    case Collection = 'Collection';
}

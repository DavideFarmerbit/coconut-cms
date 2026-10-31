<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Data\Registry;

/** What a registered conversion is, by the interface it implements. */
enum ConversionKind
{
    /** A FieldConverter, for a retype. */
    case Converter;

    /** A FieldInitializer, for a field added or moved up. */
    case Initializer;

    /** A LevelInitializer, for the levels a reparent gains. */
    case LevelInitializer;
}

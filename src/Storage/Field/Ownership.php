<?php

namespace XyloIsCoding\CoconutCms\Storage\Field;

/**
 * Decides who gets independent undo/revision history, and the schema shape that
 * follows, for an EntityReference or Collection field. Mirrors Unreal's asset-vs-
 * subobject split: the same referenced class can be Owned through one field and
 * Shared through another, it's a property of the relationship, not the class.
 */
enum Ownership
{
    /** Independently referenceable from more than one place. A real FK column or a join table, RESTRICT. */
    case Shared;

    /** Exclusively belongs to this one relationship, no life apart from it. A back-pointer FK, CASCADE. */
    case Owned;
}

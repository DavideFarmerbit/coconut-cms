<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

/**
 * One change an admin made to editor-created types, in an editor save. Changes
 * the draft's definitions, and adds the fixups moving stored content there
 * and the default changes applied once it moved.
 */
interface SchemaOperation
{
    /** Applies this change to $draft. Throws InvalidOperation when it can't apply there. */
    public function applyTo(SchemaDraft $draft): void;

    /** How a refused save names it: "RenameField(shop:boat, size → length)". */
    public function describe(): string;
}

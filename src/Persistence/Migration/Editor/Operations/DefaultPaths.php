<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/** Reads the path a default change names, for SetDefault and InheritDefault. */
final class DefaultPaths
{
    /**
     * $path parsed, on an editor-created $type holding its first field, its
     * own or inherited. What lies below is checked when the type compiles.
     */
    public static function checked(SchemaDraft $draft, string $type, string $path): FieldPath {
        $draft->editorType($type);
        try {
            $parsed = FieldPath::fromString($path);
        } catch (InvalidArgumentException $invalid) {
            throw new InvalidOperation(sprintf('"%s" is no path: %s', $path, $invalid->getMessage()), 0, $invalid);
        }

        $head = $parsed->head();
        if (is_int($head)) {
            throw new InvalidOperation(sprintf('"%s" starts with an index, not a field.', $path));
        }

        $draft->assertHasField($type, $head);

        return $parsed;
    }
}

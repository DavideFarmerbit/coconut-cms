<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Types;

use XyloIsCoding\CoconutCms\Api\Operation\OperationResult;
use XyloIsCoding\CoconutCms\Persistence\Permission\Permissions;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;

/**
 * One entity type, as a client needs it to list and edit its entities: its
 * place in the hierarchy, its own fields, and what the caller may do. An
 * inherited field is described on the ancestor declaring it.
 */
final readonly class TypeDescription implements OperationResult
{
    /**
     * @param string|null $parent The type it extends; null for a root.
     * @param bool $editorExtensible Whether the editor may add types extending it.
     * @param array<string, FieldDescription> $fields Its own fields, by name, in order.
     */
    public function __construct(
        public string $identifier,
        public ?string $parent,
        public bool $editorExtensible,
        public TypeAccess $may,
        public array $fields,
    ) {
    }

    /** $schema, as $permissions let its caller use it. */
    public static function of(EntitySchema $schema, Permissions $permissions): self {
        $fields = [];
        foreach ($schema->fields as $name => $field) {
            $fields[(string) $name] = new FieldDescription(
                $permissions->canRead($schema->identifier, (string) $name),
                $permissions->canWrite($schema->identifier, (string) $name),
                ValueDescription::of($field),
            );
        }

        return new self($schema->identifier, $schema->parent, $schema->editorExtensible, TypeAccess::of($permissions, $schema->identifier), $fields);
    }
}

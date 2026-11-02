<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

/**
 * One type as the schema editor starts from it: everything a save may
 * change, each in the form the save's operations take it back.
 */
final readonly class TypeDefinition
{
    /**
     * @param string $source "native" for a type the code declares, "editor" for one the editor made: only an editor-made one changes.
     * @param string|null $parent The type it extends; null for a root.
     * @param bool $editorExtensible Whether the editor may add types extending it.
     * @param array<string, array<string, mixed>> $fields Its own fields, by name, in order, as addField and retypeField take one, without its name.
     * @param array<string, mixed> $defaults The defaults it sets itself, by path, in stored form: a path left out follows its ancestors.
     * @param array<string, list<array<string, mixed>>> $fieldRules Each own field's rules, by name, as setFieldRules takes them.
     * @param array<string, FieldRoles> $fieldPermissions Each own field's roles, by name; a field left out is open.
     * @param list<array<string, mixed>> $prototypeRules Its cross-field rules, as setPrototypeRules takes them.
     * @param TypeRoles|null $typePermission Its own roles; null for none: its ancestors' still apply.
     * @param list<list<string>> $uniqueGroups Each unique group over its own fields, by their names.
     */
    public function __construct(
        public string $identifier,
        public string $source,
        public ?string $parent,
        public bool $editorExtensible,
        public array $fields,
        public array $defaults,
        public array $fieldRules,
        public array $fieldPermissions,
        public array $prototypeRules,
        public ?TypeRoles $typePermission,
        public array $uniqueGroups,
    ) {
    }
}

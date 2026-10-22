<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * Everything a producer discovers about one entity type's shape, native or
 * editor-created alike, before the compiler turns it into a final EntitySchema.
 * Carries its own dense default values alongside its fields: for native, read in
 * the same reflection pass the fields themselves come from, rather than walking
 * the class a second time; for editor-created, handed over directly as data,
 * since there's no reflection to speak of. Never persisted and never handed to
 * calling code directly: the compiler is the only consumer.
 */
final readonly class EntityBlueprint
{
    /**
     * @param string $identifier A native class's name, or an editor-created
     *        prototype's identifier.
     * @param string|null $parent The CTI parent's identifier, or null for a
     *        root. For a native class, never its PHP parent unless that parent
     *        is also #[Entity]-marked.
     * @param string $table Always the real table name: an explicit #[Entity(table:
     *        ...)] override, or the derived default when none was given.
     * @param list<UniqueGroup> $uniqueGroups
     * @param PrototypeValidatorRule[] $prototypeValidators
     * @param ObjectNode $defaultValues This class's own default instance, every field
     *        it declares or inherits, already read into ValueNodes.
     */
    public function __construct(
        public string $identifier,
        public ?string $parent,
        public string $table,
        public bool $editorExtensible,
        public FieldDescriptorCollection $fields,
        public FieldPermissionCollection $fieldPermissions,
        public FieldValidatorCollection $fieldValidators,
        public array $uniqueGroups,
        public array $prototypeValidators,
        public ObjectNode $defaultValues,
    ) {
    }
}

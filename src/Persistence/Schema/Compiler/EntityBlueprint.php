<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\TypePermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * Everything a producer discovers about one entity type's shape, native or
 * editor-created alike, before the compiler turns it into a final EntitySchema.
 * Carries its default values alongside its fields: for native, dense, read in
 * the same reflection pass the fields themselves come from, rather than walking
 * the class a second time; for a type rebuilt from what's stored, only what it
 * sets itself, so what it inherits follows its ancestors as compiled now. Never
 * persisted and never handed to calling code directly: the compiler is the only
 * consumer.
 */
final readonly class EntityBlueprint
{
    /**
     * @param string $identifier A native class's identifier, or an
     *        editor-created prototype's.
     * @param SchemaSource $source Which producer built it.
     * @param string|null $parent The CTI parent's identifier, or null for a
     *        root. For a native class, never its PHP parent unless that parent
     *        is also #[Entity]-marked.
     * @param string $table Always the real table name: an explicit #[Entity(table:
     *        ...)] override, or the derived default when none was given.
     * @param list<UniqueGroup> $uniqueGroups
     * @param PrototypeValidatorRule[] $prototypeValidators
     * @param BlueprintDefaults $defaults Dense for a native class: its default
     *        instance, every field it declares or inherits, read into
     *        ValueNodes. Own for a type rebuilt from what's stored.
     * @param TypePermission|null $typePermission This type's own declaration, null for none.
     */
    public function __construct(
        public string $identifier,
        public SchemaSource $source,
        public ?string $parent,
        public string $table,
        public bool $editorExtensible,
        public FieldDescriptorCollection $fields,
        public FieldPermissionCollection $fieldPermissions,
        public FieldValidatorCollection $fieldValidators,
        public array $uniqueGroups,
        public array $prototypeValidators,
        public BlueprintDefaults $defaults,
        public ?TypePermission $typePermission = null,
    ) {
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\TypePermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * Everything the compiler resolved about one entity type's shape, native or
 * editor-created, and the only form of it that's actually persisted. Holds only
 * this type's own fields, not an ancestor's. Carries no default values and no way
 * to build an instance: those are a producer's own concern, never this one's.
 */
final readonly class EntitySchema
{
    /**
     * @param string $identifier A native class's identifier, or an
     *        editor-created prototype's.
     * @param string|null $parent The CTI parent's identifier, or null for a
     *        root. For a native class, never its PHP parent unless that parent
     *        is also #[Entity]-marked.
     * @param string $table Always the real table name: an explicit #[Entity(table:
     *        ...)] override, or the derived default when none was given.
     * @param list<UniqueGroup> $uniqueGroups
     * @param PrototypeValidatorRule[] $prototypeValidators
     * @param TypePermission|null $typePermission This type's own declaration,
     *        null for none: its ancestors' still apply.
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
        public ?TypePermission $typePermission = null,
    ) {
    }

    /*================================================================================================================*/
    // Copies

    public function withIdentifier(string $identifier): self {
        return $this->copy($identifier, $this->parent, $this->table, $this->fields, $this->uniqueGroups);
    }

    public function withParent(?string $parent): self {
        return $this->copy($this->identifier, $parent, $this->table, $this->fields, $this->uniqueGroups);
    }

    public function withTable(string $table): self {
        return $this->copy($this->identifier, $this->parent, $table, $this->fields, $this->uniqueGroups);
    }

    public function withFields(FieldDescriptorCollection $fields): self {
        return $this->copy($this->identifier, $this->parent, $this->table, $fields, $this->uniqueGroups);
    }

    /** @param list<UniqueGroup> $uniqueGroups */
    public function withUniqueGroups(array $uniqueGroups): self {
        return $this->copy($this->identifier, $this->parent, $this->table, $this->fields, $uniqueGroups);
    }

    /**
     * This schema with these values, everything else kept.
     * @param list<UniqueGroup> $uniqueGroups
     */
    private function copy(string $identifier, ?string $parent, string $table, FieldDescriptorCollection $fields, array $uniqueGroups): self {
        return new self(
            identifier: $identifier,
            parent: $parent,
            table: $table,
            editorExtensible: $this->editorExtensible,
            fields: $fields,
            fieldPermissions: $this->fieldPermissions,
            fieldValidators: $this->fieldValidators,
            uniqueGroups: $uniqueGroups,
            prototypeValidators: $this->prototypeValidators,
            typePermission: $this->typePermission,
        );
    }

    // ~Copies
    /*================================================================================================================*/
}

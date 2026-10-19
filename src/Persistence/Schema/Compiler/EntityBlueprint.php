<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Compiler;

use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * Everything a producer discovers about one class's shape, native or
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
     * @param class-string $identifier
     * @param class-string|null $parent The CTI parent, or null for a root. Never the
     *        class's PHP parent unless that parent is also #[Entity]-marked.
     * @param string $table Always the real table name: an explicit #[Entity(table:
     *        ...)] override, or the derived default when none was given.
     * @param FieldName[][] $uniqueGroups Each entry is one group's own field names.
     * @param PrototypeValidatorRule[] $prototypeValidators
     * @param array<string, mixed> $defaultValues This class's own default instance,
     *        already read into plain data keyed by FieldName. A Scalar/ValueObject/Reference 
     *        entry is the raw value. An Embed entry is a nested array of this same shape, or
     *        null. An OwningReference entry is null, or {class, fields}, fields
     *        being this same shape for the referenced object's own class. A
     *        Collection entry is a list of entries, each following the rule for the
     *        collection's own item kind.
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
        public array $defaultValues,
    ) {
    }
}

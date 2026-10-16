<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema;

use Closure;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * Everything EntityRegistrar discovers about one native class: its own declared
 * fields and every attribute attached to it or to them. Holds only this class's own
 * fields, not an ancestor's.
 */
final readonly class EntitySchema
{
    /**
     * @param class-string $identifier
     * @param class-string|null $parent The CTI parent, or null for a root. Never the
     *        class's PHP parent unless that parent is also #[Entity]-marked.
     * @param string $table Always the real table name: an explicit #[Entity(table:
     *        ...)] override, or the derived default when none was given.
     * @param FieldDescriptor[] $fields Keyed by field name.
     * @param array<string, FieldPermission> $fieldPermissions Keyed by field name; a
     *        field absent here carries no restriction.
     * @param array<string, FieldValidatorRule[]> $fieldValidators Keyed by field
     *        name; a field absent here carries no rules.
     * @param string[][] $uniqueGroups Each entry is one group's own field names.
     * @param PrototypeValidatorRule[] $prototypeValidators
     */
    public function __construct(
        public string $identifier,
        public ?string $parent,
        public string $table,
        public bool $editorExtensible,
        public array $fields,
        public array $fieldPermissions,
        public array $fieldValidators,
        public array $uniqueGroups,
        public array $prototypeValidators,
        private Closure $defaultInstanceFactory,
    ) {
    }

    /** Builds this class's own defaulted instance. */
    public function defaultInstance(): object {
        return ($this->defaultInstanceFactory)();
    }
}

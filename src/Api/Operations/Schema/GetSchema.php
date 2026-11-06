<?php

namespace XyloIsCoding\CoconutCms\Api\Operations\Schema;

use XyloIsCoding\CoconutCms\Api\Operation\Operation;
use XyloIsCoding\CoconutCms\Api\Operation\OperationContext;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\FieldCodec;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDefinition;
use XyloIsCoding\CoconutCms\Persistence\Parameters\ParameterRegistries;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * Every type as stored now, native and editor-made, with everything a save
 * may change on an editor-made one, each in the form the save's operations
 * take it: what the schema editor loads. Needs the schema-editor role: it
 * shows rules and roles an ordinary reader doesn't see.
 */
#[Operation('coconut-cms:schema.get')]
final readonly class GetSchema
{
    public function run(GetSchemaInput $input, OperationContext $context): SchemaDefinitions {
        $persistence = $context->persistence;
        $snapshot = $persistence->schemaSnapshot($context->actor);
        $fields = new FieldCodec($persistence->enums(), $persistence->valueObjects());
        $registries = new ParameterRegistries($persistence->enums(), $persistence->fieldRules());
        $prototypeRules = $persistence->prototypeRules();

        return new SchemaDefinitions(
            $snapshot->revision,
            array_map(fn (SchemaDefinition $definition): TypeDefinition => $this->typeOf($definition, $fields, $registries, $prototypeRules), $snapshot->definitions),
        );
    }

    /** $definition, each part in the form the save's operations take it. */
    private function typeOf(SchemaDefinition $definition, FieldCodec $fieldCodec, ParameterRegistries $registries, PrototypeValidatorRegistry $prototypeRules): TypeDefinition {
        $schema = $definition->schema;

        $fields = [];
        foreach ($schema->fields as $name => $field) {
            $fields[(string) $name] = $fieldCodec->write($field, named: false);
        }

        $fieldRules = [];
        foreach ($schema->fieldValidators as $name => $rules) {
            $fieldRules[(string) $name] = array_map(static fn (FieldValidatorRule $rule): array => $registries->fieldRules()->entryOf($rule, $registries), $rules);
        }

        $fieldPermissions = [];
        foreach ($schema->fieldPermissions as $name => $permission) {
            $fieldPermissions[(string) $name] = new FieldRoles($permission->read, $permission->write);
        }

        $type = $schema->typePermission;

        return new TypeDefinition(
            $schema->identifier,
            $definition->source === SchemaSource::Editor ? 'editor' : 'native',
            $schema->parent,
            $schema->editorExtensible,
            $fields,
            $definition->ownDefaults->toArray(),
            $fieldRules,
            $fieldPermissions,
            array_map(static fn (PrototypeValidatorRule $rule): array => $prototypeRules->entryOf($rule, $registries), $schema->prototypeValidators),
            $type === null ? null : new TypeRoles($type->read, $type->create, $type->update, $type->delete),
            array_map(static fn (UniqueGroup $group): array => array_map(static fn (FieldName $field): string => (string) $field, $group->fields()), $schema->uniqueGroups),
        );
    }
}

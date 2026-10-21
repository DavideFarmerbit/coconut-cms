<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Registry;

use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldPermissionCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;

/**
 * Turns an EntitySchema into plain data and back, for SchemaStore. A validator
 * rule is stored as the identifier its class was registered under plus its own
 * toStorage() configuration, and a value object as its registered identifier,
 * never as a class name: renaming or moving either class doesn't break what's
 * stored.
 */
final readonly class EntitySchemaCodec
{
    public function __construct(
        private FieldValidatorRegistry $fieldValidators,
        private PrototypeValidatorRegistry $prototypeValidators,
        private ValueObjectRegistry $valueObjects,
    ) {
    }

    /** @return array<string, mixed> Plain data only: JSON-safe. */
    public function encode(EntitySchema $schema): array {
        $fields = [];
        foreach ($schema->fields as $name => $field) {
            $fields[(string) $name] = $this->encodeDescriptor($field);
        }

        $permissions = [];
        foreach ($schema->fieldPermissions as $name => $permission) {
            $permissions[(string) $name] = ['read' => $permission->read, 'write' => $permission->write];
        }

        $validators = [];
        foreach ($schema->fieldValidators as $name => $rules) {
            $validators[(string) $name] = array_map(
                fn (object $rule): array => ['rule' => (string) $this->fieldValidators->identifierOf($rule::class), 'config' => $rule->toStorage()],
                $rules,
            );
        }

        return [
            'identifier' => $schema->identifier,
            'parent' => $schema->parent,
            'table' => $schema->table,
            'editorExtensible' => $schema->editorExtensible,
            'fields' => $fields,
            'fieldPermissions' => $permissions,
            'fieldValidators' => $validators,
            'uniqueGroups' => array_map(
                static fn (UniqueGroup $group): array => array_map('strval', $group->fields()),
                $schema->uniqueGroups,
            ),
            'prototypeValidators' => array_map(
                fn (object $rule): array => ['rule' => (string) $this->prototypeValidators->identifierOf($rule::class), 'config' => $rule->toStorage()],
                $schema->prototypeValidators,
            ),
        ];
    }

    /** @param array<string, mixed> $encoded What encode() produced. */
    public function decode(array $encoded): EntitySchema {
        $fields = FieldDescriptorCollection::empty();
        foreach ($encoded['fields'] as $name => $field) {
            $fields = $fields->with(FieldName::of($name), $this->decodeDescriptor($field));
        }

        $permissions = FieldPermissionCollection::empty();
        foreach ($encoded['fieldPermissions'] as $name => $permission) {
            $permissions = $permissions->with(FieldName::of($name), new FieldPermission($permission['read'], $permission['write']));
        }

        $validators = FieldValidatorCollection::empty();
        foreach ($encoded['fieldValidators'] as $name => $rules) {
            $validators = $validators->with(FieldName::of($name), array_map(
                fn (array $rule): object => $this->fieldValidators->resolve(Identifier::fromString($rule['rule']))::fromStorage($rule['config']),
                $rules,
            ));
        }

        return new EntitySchema(
            identifier: $encoded['identifier'],
            parent: $encoded['parent'],
            table: $encoded['table'],
            editorExtensible: $encoded['editorExtensible'],
            fields: $fields,
            fieldPermissions: $permissions,
            fieldValidators: $validators,
            uniqueGroups: array_map(
                static fn (array $names): UniqueGroup => UniqueGroup::of(...array_map(FieldName::of(...), $names)),
                $encoded['uniqueGroups'],
            ),
            prototypeValidators: array_map(
                fn (array $rule): object => $this->prototypeValidators->resolve(Identifier::fromString($rule['rule']))::fromStorage($rule['config']),
                $encoded['prototypeValidators'],
            ),
        );
    }

    /** @return array<string, mixed> */
    private function encodeDescriptor(FieldDescriptor $field): array {
        return match ($field->kind()) {
            FieldKind::Scalar => ['kind' => $field->kind()->value, 'name' => $field->name(), 'scalarType' => $field->scalarType()->value, 'enumClass' => $field->enumClass()],
            FieldKind::ValueObject => ['kind' => $field->kind()->value, 'name' => $field->name(), 'target' => (string) $this->valueObjects->identifierOf($field->target()), 'indexable' => $field->indexable()],
            FieldKind::Collection => ['kind' => $field->kind()->value, 'name' => $field->name(), 'item' => $this->encodeDescriptor($field->itemDescriptor())],
            default => ['kind' => $field->kind()->value, 'name' => $field->name(), 'target' => $field->target()],
        };
    }

    /** @param array<string, mixed> $field */
    private function decodeDescriptor(array $field): FieldDescriptor {
        return match (FieldKind::from($field['kind'])) {
            FieldKind::Scalar => FieldDescriptor::scalar($field['name'], ScalarType::from($field['scalarType']), $field['enumClass']),
            FieldKind::ValueObject => FieldDescriptor::valueObject($field['name'], $this->valueObjects->resolve(Identifier::fromString($field['target'])), $field['indexable']),
            FieldKind::Embed => FieldDescriptor::embed($field['name'], $field['target']),
            FieldKind::Reference => FieldDescriptor::reference($field['name'], $field['target']),
            FieldKind::OwningReference => FieldDescriptor::owningReference($field['name'], $field['target']),
            FieldKind::Collection => FieldDescriptor::collection($field['name'], $this->decodeDescriptor($field['item'])),
        };
    }
}

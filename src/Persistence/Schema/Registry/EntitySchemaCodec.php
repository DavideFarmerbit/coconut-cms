<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Registry;

use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Class\TypePermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\FieldPermission;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\BrokenField;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\BrokenFieldReason;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\LostAncestor;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\LostAncestorReason;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\NoTypeRecord;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
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
 * toStorage() configuration, and a value object or an enum as its registered
 * identifier, never as a class name: renaming or moving any of them doesn't
 * break what's stored.
 */
final readonly class EntitySchemaCodec
{
    public function __construct(
        private FieldValidatorRegistry $fieldValidators,
        private PrototypeValidatorRegistry $prototypeValidators,
        private ValueObjectRegistry $valueObjects,
        private EnumRegistry $enums,
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
            'typePermission' => $schema->typePermission === null ? null : [
                'read' => $schema->typePermission->read,
                'create' => $schema->typePermission->create,
                'update' => $schema->typePermission->update,
                'delete' => $schema->typePermission->delete,
            ],
            'fieldValidators' => $validators,
            'uniqueGroups' => array_map(
                static fn (UniqueGroup $group): array => array_map('strval', $group->fields()),
                $schema->uniqueGroups,
            ),
            'prototypeValidators' => array_map(
                fn (object $rule): array => ['rule' => (string) $this->prototypeValidators->identifierOf($rule::class), 'config' => $rule->toStorage()],
                $schema->prototypeValidators,
            ),
            'noTypeRecords' => array_map($this->encodeRecord(...), $schema->noTypeRecords),
        ];
    }

    /** @return array<string, mixed> */
    private function encodeRecord(NoTypeRecord $record): array {
        return match (true) {
            $record instanceof BrokenField => [
                'kind' => 'brokenField',
                'id' => $record->id,
                'name' => $record->name,
                'descriptor' => $this->encodeDescriptor($record->descriptor),
                'origin' => $record->origin,
                'reason' => $record->reason->value,
            ],
            $record instanceof LostAncestor => [
                'kind' => 'lostAncestor',
                'id' => $record->id,
                'ancestor' => $record->ancestor,
                'reason' => $record->reason->value,
                'newParent' => $record->newParent,
            ],
        };
    }

    /** @param array<string, mixed> $encoded What encodeRecord() produced. */
    private function decodeRecord(array $encoded): NoTypeRecord {
        return match ($encoded['kind']) {
            'brokenField' => new BrokenField($encoded['id'], $encoded['name'], $this->decodeDescriptor($encoded['descriptor']), $encoded['origin'], BrokenFieldReason::from($encoded['reason'])),
            'lostAncestor' => new LostAncestor($encoded['id'], $encoded['ancestor'], LostAncestorReason::from($encoded['reason']), $encoded['newParent']),
        };
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
            typePermission: $encoded['typePermission'] === null ? null : new TypePermission(
                $encoded['typePermission']['read'],
                $encoded['typePermission']['create'],
                $encoded['typePermission']['update'],
                $encoded['typePermission']['delete'],
            ),
            noTypeRecords: array_map($this->decodeRecord(...), $encoded['noTypeRecords']),
        );
    }

    /** @return array<string, mixed> */
    private function encodeDescriptor(FieldDescriptor $field): array {
        return match ($field->kind()) {
            FieldKind::Scalar => [
                'kind' => $field->kind()->value,
                'name' => $field->name(),
                'scalarType' => $field->scalarType()->value,
                'enum' => $field->enumClass() === null ? null : (string) $this->enums->identifierOf($field->enumClass()),
                'enumBacking' => $field->enumBacking()?->value,
                'nullability' => $field->nullability()->value,
            ],
            FieldKind::ValueObject => [
                'kind' => $field->kind()->value,
                'name' => $field->name(),
                'target' => (string) $this->valueObjects->identifierOf($field->target()),
                'indexable' => $field->indexable(),
                'nullability' => $field->nullability()->value,
            ],
            FieldKind::OwningReference => ['kind' => $field->kind()->value, 'name' => $field->name(), 'target' => $field->target(), 'nullability' => $field->nullability()->value],
            FieldKind::Collection => ['kind' => $field->kind()->value, 'name' => $field->name(), 'item' => $this->encodeDescriptor($field->itemDescriptor())],
            FieldKind::Embed, FieldKind::Reference => ['kind' => $field->kind()->value, 'name' => $field->name(), 'target' => $field->target()],
        };
    }

    /** @param array<string, mixed> $field */
    private function decodeDescriptor(array $field): FieldDescriptor {
        return match (FieldKind::from($field['kind'])) {
            FieldKind::Scalar => FieldDescriptor::scalar(
                $field['name'],
                ScalarType::from($field['scalarType']),
                Nullability::from($field['nullability']),
                $field['enum'] === null ? null : $this->enums->resolve(Identifier::fromString($field['enum'])),
                ScalarType::tryFrom($field['enumBacking'] ?? ''),
            ),
            FieldKind::ValueObject => FieldDescriptor::valueObject(
                $field['name'],
                $this->valueObjects->resolve(Identifier::fromString($field['target'])),
                $field['indexable'],
                Nullability::from($field['nullability']),
            ),
            FieldKind::Embed => FieldDescriptor::embed($field['name'], $field['target']),
            FieldKind::Reference => FieldDescriptor::reference($field['name'], $field['target']),
            FieldKind::OwningReference => FieldDescriptor::owningReference($field['name'], $field['target'], Nullability::from($field['nullability'])),
            FieldKind::Collection => FieldDescriptor::collection($field['name'], $this->decodeDescriptor($field['item'])),
        };
    }
}

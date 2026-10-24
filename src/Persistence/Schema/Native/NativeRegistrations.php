<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;

/**
 * Every value object, enum and validator rule class native blueprints use,
 * found through their fields and validators, each registered under its
 * NativeIdentifiers::storedAs() identifier. Nothing is listed by hand, so
 * nothing can be forgotten.
 */
final class NativeRegistrations
{
    /**
     * The classes $blueprints use, by what they are.
     * @param array<string, EntityBlueprint> $blueprints
     * @return array{valueObjects: list<class-string>, enums: list<class-string>, fieldValidators: list<class-string>, prototypeValidators: list<class-string>}
     */
    public static function classesOf(array $blueprints): array {
        $classes = ['valueObjects' => [], 'enums' => [], 'fieldValidators' => [], 'prototypeValidators' => []];

        foreach ($blueprints as $blueprint) {
            foreach ($blueprint->fields as $field) {
                $held = $field->kind() === FieldKind::Collection ? $field->itemDescriptor() : $field;
                self::collectField($held, $classes);
            }

            foreach ($blueprint->fieldValidators as $rules) {
                foreach ($rules as $rule) {
                    $classes['fieldValidators'][] = $rule::class;
                }
            }

            foreach ($blueprint->prototypeValidators as $rule) {
                $classes['prototypeValidators'][] = $rule::class;
            }
        }

        return array_map(static fn (array $each): array => array_values(array_unique($each)), $classes);
    }

    /**
     * Registers every class in $classes, as classesOf() lists them: a request
     * registers them from a cache, without reading any blueprint.
     * @param array{valueObjects: list<class-string>, enums: list<class-string>, fieldValidators: list<class-string>, prototypeValidators: list<class-string>} $classes
     */
    public static function registerAll(
        array $classes,
        ValueObjectRegistry $valueObjects,
        EnumRegistry $enums,
        FieldValidatorRegistry $fieldValidators,
        PrototypeValidatorRegistry $prototypeValidators,
    ): void {
        foreach ($classes['valueObjects'] as $class) {
            $valueObjects->register(NativeIdentifiers::storedAs($class), $class);
        }

        foreach ($classes['enums'] as $class) {
            $enums->register(NativeIdentifiers::storedAs($class), $class);
        }

        foreach ($classes['fieldValidators'] as $class) {
            $fieldValidators->register(NativeIdentifiers::storedAs($class), $class);
        }

        foreach ($classes['prototypeValidators'] as $class) {
            $prototypeValidators->register(NativeIdentifiers::storedAs($class), $class);
        }
    }

    /** @param array{valueObjects: list<class-string>, enums: list<class-string>, fieldValidators: list<class-string>, prototypeValidators: list<class-string>} $classes */
    private static function collectField(FieldDescriptor $field, array &$classes): void {
        if ($field->kind() === FieldKind::ValueObject) {
            $classes['valueObjects'][] = $field->target();
        }

        if ($field->kind() === FieldKind::Scalar && $field->scalarType() === ScalarType::Enum) {
            $classes['enums'][] = $field->enumClass();
        }
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Parameters\ConstructorShape;
use XyloIsCoding\CoconutCms\Persistence\Parameters\ParameterType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Compiler\EntityBlueprint;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\EnumRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ValueObjectRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldValidatorRule;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * Every value object, enum and validator rule class native blueprints use,
 * found through their fields and validators, plus the rules modules list for
 * editor-created types. Each is registered under its NativeIdentifiers::storedAs()
 * identifier. What the code uses is never listed by hand, so it can't be
 * forgotten.
 */
final class NativeRegistrations
{
    /**
     * The classes $blueprints use, with the validator rules modules list, by
     * what they are.
     * @param array<string, EntityBlueprint> $blueprints
     * @param list<class-string> $listedValidators What every Module::validators() lists.
     * @return array{valueObjects: list<class-string>, enums: list<class-string>, fieldValidators: list<class-string>, prototypeValidators: list<class-string>}
     */
    public static function classesOf(array $blueprints, array $listedValidators = []): array {
        $classes = ['valueObjects' => [], 'enums' => [], 'fieldValidators' => [], 'prototypeValidators' => []];

        foreach ($listedValidators as $class) {
            $classes[self::validatorKind($class)][] = $class;
        }

        foreach ($blueprints as $blueprint) {
            foreach ($blueprint->fields as $field) {
                $held = $field->kind() === FieldKind::Collection ? $field->itemDescriptor() : $field;
                self::collectField($held, $classes);
            }

            foreach ($blueprint->fieldValidators as $rules) {
                foreach ($rules as $rule) {
                    self::collectRule($rule, $classes);
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

    /**
     * Which list a listed rule class belongs in. Throws when it's neither kind
     * of rule: there'd be nothing to register it as.
     * @param class-string $class
     */
    private static function validatorKind(string $class): string {
        return match (true) {
            is_subclass_of($class, FieldValidatorRule::class) => 'fieldValidators',
            is_subclass_of($class, PrototypeValidatorRule::class) => 'prototypeValidators',
            default => throw new InvalidArgumentException(sprintf('%s is listed as a validator, but implements neither %s nor %s.', $class, FieldValidatorRule::class, PrototypeValidatorRule::class)),
        };
    }

    /**
     * $rule's class, and every rule it holds in its parameters, at any depth:
     * each is stored under its own identifier.
     * @param array{valueObjects: list<class-string>, enums: list<class-string>, fieldValidators: list<class-string>, prototypeValidators: list<class-string>} $classes
     */
    private static function collectRule(FieldValidatorRule $rule, array &$classes): void {
        $classes['fieldValidators'][] = $rule::class;
        foreach (ConstructorShape::of($rule::class)->parameters as $parameter) {
            if ($parameter->type === ParameterType::FieldRules) {
                foreach ($rule->{$parameter->name} as $held) {
                    self::collectRule($held, $classes);
                }
            }
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

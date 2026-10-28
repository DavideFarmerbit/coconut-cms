<?php

namespace XyloIsCoding\CoconutCms\Api\Input;

use BackedEnum;
use JsonException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use XyloIsCoding\CoconutCms\Api\Error\InvalidInput;
use XyloIsCoding\CoconutCms\Api\Operation\OperationInput;

/**
 * Builds an operation's input from plain data: a decoded JSON body, or
 * command-line arguments. Every value is checked against its parameter's
 * type; a key missing, unknown, or of the wrong type fails with
 * InvalidInput, a message for each path, before the operation runs.
 *
 * From the command line every value is a string: with $fromStrings, an int,
 * a float, a bool or an int-backed enum is read from its text, and a mixed
 * value as JSON when it is JSON, as the string otherwise.
 */
final readonly class InputReader
{
    /**
     * The $class input $data describes.
     * @template T of OperationInput
     * @param class-string<T> $class
     * @param mixed $data What JSON decoded to, as associative arrays.
     * @return T
     * @throws InvalidInput
     */
    public function read(string $class, mixed $data, bool $fromStrings = false): OperationInput {
        $errors = [];
        $input = $this->input($class, $data, '', $fromStrings, $errors);
        if ($errors !== []) {
            throw new InvalidInput($errors);
        }

        return $input;
    }

    /**
     * The $class input $data describes at $path; null after noting an error.
     * @param class-string $class
     * @param array<string, string> $errors Filled in.
     */
    private function input(string $class, mixed $data, string $path, bool $fromStrings, array &$errors): ?OperationInput {
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            $errors[$path] = 'Must be an object.';

            return null;
        }

        $shape = InputShape::of($class);
        $arguments = [];
        $before = count($errors);
        foreach ($shape->parameters as $parameter) {
            $at = self::join($path, $parameter->name);
            if (!array_key_exists($parameter->name, $data)) {
                if ($parameter->optional) {
                    $arguments[$parameter->name] = $parameter->default;
                } else {
                    $errors[$at] = 'Missing.';
                }

                continue;
            }

            $arguments[$parameter->name] = $this->value($parameter->type, $data[$parameter->name], $at, $fromStrings, $errors);
        }

        $known = array_flip(array_map(static fn (InputParameter $parameter): string => $parameter->name, $shape->parameters));
        foreach (array_keys($data) as $key) {
            if (!isset($known[$key])) {
                $errors[self::join($path, (string) $key)] = 'Unknown: there is no such input.';
            }
        }

        return count($errors) === $before ? (new ReflectionClass($class))->newInstanceArgs($arguments) : null;
    }

    /**
     * $value, checked against $type: null after noting an error.
     * @param array<string, string> $errors Filled in.
     */
    private function value(InputType $type, mixed $value, string $path, bool $fromStrings, array &$errors): mixed {
        if ($value === null) {
            if (!$type->nullable) {
                $errors[$path] = 'Can\'t be null.';
            }

            return null;
        }

        $read = match ($type->kind) {
            InputKind::String => is_string($value) ? $value : null,
            InputKind::Int => self::int($value, $fromStrings),
            InputKind::Float => self::float($value, $fromStrings),
            InputKind::Bool => self::bool($value, $fromStrings),
            InputKind::Mixed => $fromStrings && is_string($value) ? self::decoded($value) : $value,
            InputKind::Enum => $this->enum($type->class, $value, $fromStrings),
            InputKind::Input => $this->input($type->class, $value, $path, $fromStrings, $errors),
            InputKind::List => $this->list($type->item, $value, $path, $fromStrings, $errors),
        };

        if ($read === null && !isset($errors[$path]) && !in_array($type->kind, [InputKind::Input, InputKind::List, InputKind::Mixed], true)) {
            $errors[$path] = self::expected($type);
        }

        return $read;
    }

    /**
     * Each item of $value, checked against $item; null after noting an error.
     * @param array<string, string> $errors Filled in.
     * @return list<mixed>|null
     */
    private function list(InputType $item, mixed $value, string $path, bool $fromStrings, array &$errors): ?array {
        if (!is_array($value) || !array_is_list($value)) {
            $errors[$path] = 'Must be a list.';

            return null;
        }

        $before = count($errors);
        $items = [];
        foreach ($value as $index => $itemValue) {
            $items[] = $this->value($item, $itemValue, self::join($path, (string) $index), $fromStrings, $errors);
        }

        return count($errors) === $before ? $items : null;
    }

    /** The case of $class $value names; null when it names none. */
    private function enum(string $class, mixed $value, bool $fromStrings): ?BackedEnum {
        $isInt = self::backing($class) === 'int';
        if ($isInt && $fromStrings && is_string($value)) {
            $value = self::int($value, true);
        }

        if ($isInt ? !is_int($value) : !is_string($value)) {
            return null;
        }

        return $class::tryFrom($value);
    }

    private static function int(mixed $value, bool $fromStrings): ?int {
        if (is_int($value)) {
            return $value;
        }

        return $fromStrings && is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    private static function float(mixed $value, bool $fromStrings): ?float {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        return $fromStrings && is_string($value) && is_numeric($value) ? (float) $value : null;
    }

    private static function bool(mixed $value, bool $fromStrings): ?bool {
        if (is_bool($value)) {
            return $value;
        }

        return $fromStrings ? match ($value) {
            'true' => true,
            'false' => false,
            default => null,
        } : null;
    }

    /** $text as the JSON value it is: "200" is 200, "true" is true; text that isn't JSON stays text. */
    private static function decoded(string $text): mixed {
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $text;
        }
    }

    /** What $type takes, for an error message. */
    private static function expected(InputType $type): string {
        if ($type->kind === InputKind::Enum) {
            $cases = array_map(static fn (BackedEnum $case): string => json_encode($case->value), $type->class::cases());

            return sprintf('Must be one of %s.', implode(', ', $cases));
        }

        return sprintf('Must be %s.', match ($type->kind) {
            InputKind::String => 'a string',
            InputKind::Int => 'an integer',
            InputKind::Float => 'a number',
            InputKind::Bool => 'true or false',
            default => 'valid',
        });
    }

    /** "int" or "string": what $class is backed by. */
    private static function backing(string $class): string {
        $type = (new ReflectionEnum($class))->getBackingType();

        return $type instanceof ReflectionNamedType ? $type->getName() : 'string';
    }

    /** $key under $path: "where.0.path". */
    private static function join(string $path, string $key): string {
        return $path === '' ? $key : $path . '.' . $key;
    }
}

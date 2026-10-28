<?php

namespace XyloIsCoding\CoconutCms\Api\Output;

use BackedEnum;
use LogicException;
use ReflectionClass;
use ReflectionProperty;
use stdClass;
use UnitEnum;
use XyloIsCoding\CoconutCms\Api\Input\DocTypes;

/**
 * Turns an operation's result into plain data json_encode() writes as is:
 * the input rules in reverse. An object's public properties become a JSON
 * object's keys; a backed enum its value; a list a JSON array. An array a
 * constructor's @param calls `array<string, T>` is always a JSON object,
 * even empty.
 */
final class ResultWriter
{
    /** @var array<class-string, array<string, bool>> Whether each property is a map, by class. */
    private array $maps = [];

    /** $value as plain data. */
    public function write(mixed $value): mixed {
        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => $this->array($value, false),
            is_object($value) => $this->object($value),
            default => throw new LogicException(sprintf('A result can\'t hold a %s.', get_debug_type($value))),
        };
    }

    /**
     * $object's public properties, written.
     * @return array<string, mixed>|stdClass
     */
    private function object(object $object): array|stdClass {
        $written = [];
        $maps = $this->mapsOf($object::class);
        foreach ((new ReflectionClass($object))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || !$property->isInitialized($object)) {
                continue;
            }

            $value = $property->getValue($object);
            $name = $property->getName();
            $written[$name] = is_array($value) ? $this->array($value, $maps[$name] ?? false) : $this->write($value);
        }

        return self::asObject($written);
    }

    /**
     * $array's items, written: a JSON object when it's a map, or has keys
     * that aren't a list's.
     * @param array<mixed> $array
     * @return array<mixed>|stdClass
     */
    private function array(array $array, bool $isMap): array|stdClass {
        $written = array_map($this->write(...), $array);

        return !$isMap && array_is_list($array) ? $written : self::asObject($written);
    }

    /**
     * $map in a form json_encode() writes as an object: an associative array
     * as is, keeping any key, "" included; one that would read as a list,
     * empty or keyed 0, 1…, as an object.
     * @param array<mixed> $map
     * @return array<mixed>|stdClass
     */
    private static function asObject(array $map): array|stdClass {
        return $map === [] || array_is_list($map) ? (object) $map : $map;
    }

    /**
     * Which of $class's promoted properties its constructor's @param calls a
     * map.
     * @param class-string $class
     * @return array<string, bool>
     */
    private function mapsOf(string $class): array {
        if (isset($this->maps[$class])) {
            return $this->maps[$class];
        }

        $constructor = (new ReflectionClass($class))->getConstructor();
        $maps = [];
        foreach ($constructor === null ? [] : DocTypes::paramsOf($constructor) as $name => $type) {
            $maps[$name] = DocTypes::isStringMap($type);
        }

        return $this->maps[$class] = $maps;
    }
}

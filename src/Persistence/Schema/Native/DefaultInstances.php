<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Method\DefaultInstance;

/**
 * Builds a native class's default instance: through its static method marked
 * #[DefaultInstance], or its zero-argument constructor. The reader reads
 * default values off it; loading copies its non-field state into every
 * object it builds, the way Unreal copies a class default object.
 */
final class DefaultInstances
{
    /** @param ReflectionClass<object> $reflection */
    public static function build(ReflectionClass $reflection): object {
        $markedMethods = array_values(array_filter(
            $reflection->getMethods(),
            static fn (ReflectionMethod $method): bool => $method->getAttributes(DefaultInstance::class) !== [],
        ));

        if (count($markedMethods) > 1) {
            throw new InvalidArgumentException(sprintf(
                '%s marks more than one method #[DefaultInstance].',
                $reflection->getName(),
            ));
        }

        if ($markedMethods !== []) {
            $method = $markedMethods[0];
            if (!$method->isStatic() || $method->getNumberOfParameters() !== 0) {
                throw new InvalidArgumentException(sprintf(
                    '%s::%s() must be static and take no parameters to carry #[DefaultInstance].',
                    $reflection->getName(),
                    $method->getName(),
                ));
            }

            return $method->invoke(null);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new InvalidArgumentException(sprintf(
                '%s needs a zero-argument constructor, or a static method marked #[DefaultInstance].',
                $reflection->getName(),
            ));
        }

        return $reflection->newInstance();
    }
}

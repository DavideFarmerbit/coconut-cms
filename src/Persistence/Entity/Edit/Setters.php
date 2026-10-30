<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Edit;

use Closure;
use LogicException;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Setter;

/**
 * Sets a native field the way PHP code sets it: through the #[Setter] its
 * declaring class names, or on the property. What every edit by path goes
 * through, NativeEditor's and DynamicEntity's alike.
 */
final class Setters
{
    /**
     * Gives $value to $property's setter on $object, or sets the property.
     * The setter is called from the declaring class's scope: any visibility,
     * with PHP's own dispatch, so a subclass's override runs.
     * @throws EditRefused When the setter refuses the value.
     * @throws LogicException For a readonly property with no setter.
     */
    public static function assign(object $object, ReflectionProperty $property, mixed $value): void {
        $setter = ($property->getAttributes(Setter::class)[0] ?? null)?->newInstance();
        if ($setter !== null) {
            Closure::bind(static function (object $object, string $method, mixed $value): void {
                $object->{$method}($value);
            }, null, $property->getDeclaringClass()->getName())($object, $setter->method, $value);

            return;
        }

        if ($property->isReadOnly()) {
            throw new LogicException(sprintf('%s::$%s is readonly: only a #[Setter] could change it.', $property->getDeclaringClass()->getName(), $property->getName()));
        }

        $property->setValue($object, $value);
    }
}

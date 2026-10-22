<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Native;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Collection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Embed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\OwningReference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Reference;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Scalar;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\ValueObject;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * Builds a native object back from its resolved value tree, the reverse of
 * NativeValueReader. Never runs a constructor per object: each class's
 * default instance is built once, then for every object a clone of it gives
 * the non-field state, fresh where the class's __clone() says so, and every
 * field is set from the tree. Fields are set exactly once, so a readonly one
 * works too.
 */
final class NativeObjectBuilder
{
    /** @var array<class-string, object> Each class's default instance, built once. */
    private array $defaults = [];

    /**
     * @param NativeEntityRegistry $classes Turns the identifier an owned
     *        object is stored under into the class to build.
     * @param Closure(class-string, string): ?object $referenceOf The object a
     *        Reference holds: given the field's declared class and the stored
     *        id. Null when the target no longer exists.
     */
    public function __construct(
        private readonly NativeEntityRegistry $classes,
        private readonly Closure $referenceOf,
    ) {
    }

    /**
     * @param class-string $class
     * @param object|null $into An uninitialized object of $class to fill, such
     *        as a lazy ghost being initialized; a new one when null.
     * @param Closure(FieldPath, object): void|null $onOwned Told about every
     *        owned object built, with its path from $class.
     */
    public function build(string $class, ObjectNode $tree, ?object $into = null, ?Closure $onOwned = null): object {
        return $this->buildAs(new ReflectionClass($class), $tree, $into, FieldPath::of(...), $onOwned ?? static function (): void {
        });
    }

    /**
     * @param ReflectionClass<object> $class
     * @param Closure(FieldName): FieldPath $pathOf Turns one of $class's field names into its path.
     */
    private function buildAs(ReflectionClass $class, ObjectNode $tree, ?object $into, Closure $pathOf, Closure $onOwned): object {
        $object = $into ?? $class->newInstanceWithoutConstructor();
        $fields = NativeFields::of($class);

        $fresh = clone ($this->defaults[$class->getName()] ??= DefaultInstances::build($class));
        foreach (self::nonFieldPropertiesOf($class, $fields) as $property) {
            if ($property->isInitialized($fresh)) {
                $property->setValue($object, $property->getValue($fresh));
            }
        }

        foreach ($fields as $property) {
            $name = FieldName::of($property->getName());
            $attribute = NativeFields::kindAttributesOf($property)[0]->newInstance();
            $property->setValue($object, $this->valueOf($attribute, $property, $tree->find($name), $pathOf($name), $onOwned));
        }

        return $object;
    }

    /** Dispatches on the field's kind. */
    private function valueOf(object $attribute, ReflectionProperty $property, ?ValueNode $node, FieldPath $path, Closure $onOwned): mixed {
        return match (true) {
            $attribute instanceof Scalar => ScalarValues::fromStorage($attribute->type, self::declaredClassOf($property), LeafNode::valueOf($node)),
            $attribute instanceof ValueObject => self::valueObjectOf(self::declaredClassOf($property), LeafNode::valueOf($node)),
            $attribute instanceof Reference => $this->referenceOf(self::declaredClassOf($property), LeafNode::valueOf($node)),
            $attribute instanceof Embed => $this->buildAs(new ReflectionClass(self::declaredClassOf($property)), ObjectNode::from($node) ?? ObjectNode::empty(), null, $path->embed(...), $onOwned),
            $attribute instanceof OwningReference => $this->ownedOf(OwnedNode::from($node), $path, $onOwned),
            $attribute instanceof Collection => $this->itemsOf($attribute, CollectionNode::from($node)?->items ?? [], $path, $onOwned),
        };
    }

    /**
     * @param list<ValueNode|null> $items
     * @return list<mixed>
     */
    private function itemsOf(Collection $attribute, array $items, FieldPath $path, Closure $onOwned): array {
        $built = [];
        foreach ($items as $index => $item) {
            $itemPath = $path->traverse($index);
            $built[] = match ($attribute->itemKind) {
                FieldKind::Scalar => ScalarValues::fromStorage($attribute->itemScalarType, $attribute->itemEnumClass, LeafNode::valueOf($item)),
                FieldKind::ValueObject => self::valueObjectOf($attribute->itemTarget, LeafNode::valueOf($item)),
                FieldKind::Reference => $this->referenceOf($attribute->itemTarget, LeafNode::valueOf($item)),
                FieldKind::Embed => $this->buildAs(new ReflectionClass($attribute->itemTarget), ObjectNode::from($item) ?? ObjectNode::empty(), null, $itemPath->traverse(...), $onOwned),
                FieldKind::OwningReference => $this->ownedOf(OwnedNode::from($item), $itemPath, $onOwned),
            };
        }

        return $built;
    }

    private function ownedOf(?OwnedNode $node, FieldPath $path, Closure $onOwned): ?object {
        if ($node === null) {
            return null;
        }

        $owned = $this->buildAs(new ReflectionClass($this->classes->classOf($node->identifier)), $node->fields, null, $path->traverse(...), $onOwned);
        $onOwned($path, $owned);

        return $owned;
    }

    private function referenceOf(string $declaredClass, ?string $id): ?object {
        return $id === null ? null : ($this->referenceOf)($declaredClass, $id);
    }

    private static function valueObjectOf(string $class, ?string $stored): ?object {
        return $stored === null ? null : $class::fromStorage($stored);
    }

    /** The class $property is declared as, or null for a builtin type. */
    private static function declaredClassOf(ReflectionProperty $property): ?string {
        $type = $property->getType();

        return $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
    }

    /**
     * Every property $class declares or inherits that isn't one of $fields.
     * Reflects each class in the chain separately: getProperties() on the
     * most-derived class alone drops an ancestor's private ones.
     * @param ReflectionClass<object> $class
     * @param list<ReflectionProperty> $fields
     * @return list<ReflectionProperty>
     */
    private static function nonFieldPropertiesOf(ReflectionClass $class, array $fields): array {
        $isField = [];
        foreach ($fields as $field) {
            $isField[$field->getDeclaringClass()->getName() . '::' . $field->getName()] = true;
        }

        $properties = [];
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                $key = $property->getDeclaringClass()->getName() . '::' . $property->getName();
                if ($property->getDeclaringClass()->getName() === $current->getName() && !$property->isStatic() && !isset($isField[$key])) {
                    $properties[] = $property;
                }
            }
        }

        return $properties;
    }
}

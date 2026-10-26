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
 * works too. A field its reader may not read is never set: the property stays
 * uninitialized, and reading it fails loudly.
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
     * @param Closure(ReflectionProperty): bool|null $readable Whether a field
     *        may be read; null for every field.
     */
    public function __construct(
        private readonly NativeEntityRegistry $classes,
        private readonly Closure $referenceOf,
        private readonly ?Closure $readable = null,
    ) {
    }

    /**
     * @param class-string $class
     * @param object|null $into An uninitialized object of $class to fill, such
     *        as a lazy ghost being initialized; a new one when null.
     * @param Closure(FieldPath, object): void|null $onOwned Told about every
     *        owned object built, with its path from $class.
     * @param Closure(object, string, FieldPath, ?ValueNode): void|null $onHidden
     *        Told about every field left unset, with its object, its name, its
     *        path from $class, and what it holds.
     * @param Closure(FieldPath): ?object|null $known The object already
     *        standing for the owned object at a path, if any: reused, so one
     *        entity stays one object. A lazy ghost not used yet is filled.
     */
    public function build(string $class, ObjectNode $tree, ?object $into = null, ?Closure $onOwned = null, ?Closure $onHidden = null, ?Closure $known = null): object {
        $nothing = static function (): void {
        };

        return $this->buildAs(new ReflectionClass($class), $tree, $into, FieldPath::of(...), $onOwned ?? $nothing, $onHidden ?? $nothing, $known ?? static fn (): ?object => null);
    }

    /**
     * @param ReflectionClass<object> $class
     * @param Closure(FieldName): FieldPath $pathOf Turns one of $class's field names into its path.
     */
    private function buildAs(ReflectionClass $class, ObjectNode $tree, ?object $into, Closure $pathOf, Closure $onOwned, Closure $onHidden, Closure $known): object {
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
            if ($this->readable !== null && !($this->readable)($property)) {
                self::unset($object, $property);
                $onHidden($object, $property->getName(), $pathOf($name), $tree->find($name));
                continue;
            }

            $attribute = NativeFields::kindAttributesOf($property)[0]->newInstance();
            $property->setValue($object, $this->valueOf($attribute, $property, $tree->find($name), $pathOf($name), $onOwned, $onHidden, $known));
        }

        return $object;
    }

    /**
     * Leaves $property of $object unset. A property with a default holds it
     * from the start, in a new instance and in a ghost being initialized.
     */
    private static function unset(object $object, ReflectionProperty $property): void {
        // Bound to the declaring class, so a private property can be unset too.
        Closure::bind(static function (object $object, string $name): void {
            unset($object->{$name});
        }, null, $property->getDeclaringClass()->getName())($object, $property->getName());
    }

    /** Dispatches on the field's kind. */
    private function valueOf(object $attribute, ReflectionProperty $property, ?ValueNode $node, FieldPath $path, Closure $onOwned, Closure $onHidden, Closure $known): mixed {
        return match (true) {
            $attribute instanceof Scalar => ScalarValues::fromStorage($attribute->type, self::declaredClassOf($property), LeafNode::valueOf($node)),
            $attribute instanceof ValueObject => self::valueObjectOf(self::declaredClassOf($property), LeafNode::valueOf($node)),
            $attribute instanceof Reference => $this->referenceOf(self::declaredClassOf($property), LeafNode::valueOf($node)),
            $attribute instanceof Embed => $this->buildAs(new ReflectionClass(self::declaredClassOf($property)), ObjectNode::from($node) ?? ObjectNode::empty(), null, $path->embed(...), $onOwned, $onHidden, $known),
            $attribute instanceof OwningReference => $this->ownedOf(OwnedNode::from($node), $path, $onOwned, $onHidden, $known),
            $attribute instanceof Collection => $this->itemsOf($attribute, CollectionNode::from($node)?->items ?? [], $path, $onOwned, $onHidden, $known),
        };
    }

    /**
     * @param list<ValueNode|null> $items
     * @return list<mixed>
     */
    private function itemsOf(Collection $attribute, array $items, FieldPath $path, Closure $onOwned, Closure $onHidden, Closure $known): array {
        $built = [];
        foreach ($items as $index => $item) {
            $itemPath = $path->traverse($index);
            $built[] = match ($attribute->itemKind) {
                FieldKind::Scalar => ScalarValues::fromStorage($attribute->itemScalarType, $attribute->itemEnumClass, LeafNode::valueOf($item)),
                FieldKind::ValueObject => self::valueObjectOf($attribute->itemTarget, LeafNode::valueOf($item)),
                FieldKind::Reference => $this->referenceOf($attribute->itemTarget, LeafNode::valueOf($item)),
                FieldKind::Embed => $this->buildAs(new ReflectionClass($attribute->itemTarget), ObjectNode::from($item) ?? ObjectNode::empty(), null, $itemPath->traverse(...), $onOwned, $onHidden, $known),
                FieldKind::OwningReference => $this->ownedOf(OwnedNode::from($item), $itemPath, $onOwned, $onHidden, $known),
            };
        }

        return $built;
    }

    /**
     * The owned object at $path: the one already known for it, or a new one.
     * A known object in use is kept as it is, the way loading a known entity
     * gives it back; a lazy ghost not used yet is filled from $node.
     */
    private function ownedOf(?OwnedNode $node, FieldPath $path, Closure $onOwned, Closure $onHidden, Closure $known): ?object {
        if ($node === null) {
            return null;
        }

        $into = $known($path);
        if ($into !== null) {
            $reflection = new ReflectionClass($into);
            if (!$reflection->isUninitializedLazyObject($into)) {
                $onOwned($path, $into);

                return $into;
            }

            // Filled here instead of by its own initializer: reached through
            // its owner, it's no longer only a Reference's target.
            $reflection->markLazyObjectAsInitialized($into);
        }

        $owned = $this->buildAs(new ReflectionClass($this->classes->classOf($node->identifier)), $node->fields, $into, $path->traverse(...), $onOwned, $onHidden, $known);
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

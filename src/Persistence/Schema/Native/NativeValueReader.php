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
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\StorableValues;

/**
 * Reads a native object's fields into a tree of ValueNodes, every leaf already
 * in the plain form it's stored as: the reader does it for a class's default
 * instance, content for a live entity. Recurses into Embed and OwningReference
 * values. An Embed value is read through the declared target class, never a
 * runtime subclass: type erasure. An OwningReference value is read through its
 * own actual class: polymorphic, so the class is recorded alongside its fields.
 */
final readonly class NativeValueReader
{
    /**
     * @param Closure(object): string $referenceId Turns the object a Reference
     *        holds into the id stored for it.
     */
    public function __construct(
        private Closure $referenceId,
    ) {
    }

    /** $object's fields, read through its own class. */
    public function read(object $object): ObjectNode {
        return $this->readAs(new ReflectionClass($object), $object);
    }

    /** @param ReflectionClass<object> $governingClass Whose fields to read off $object. */
    private function readAs(ReflectionClass $governingClass, object $object): ObjectNode {
        $values = ObjectNode::empty();

        foreach (NativeFields::of($governingClass) as $property) {
            $attribute = NativeFields::kindAttributesOf($property)[0]->newInstance();
            $values = $values->with(FieldName::of($property->getName()), $this->readField($attribute, $property, $property->getValue($object)));
        }

        return $values;
    }

    private function readField(object $attribute, ReflectionProperty $property, mixed $value): ?ValueNode {
        return match (true) {
            $attribute instanceof Scalar => new LeafNode(ScalarValues::toStorage($attribute->type, $value)),
            $attribute instanceof ValueObject => new LeafNode($value === null ? null : StorableValues::toStorage($value)),
            $attribute instanceof Reference => new LeafNode($value === null ? null : ($this->referenceId)($value)),
            $attribute instanceof Embed => $value === null ? null : $this->readAs(new ReflectionClass(self::declaredClassOf($property)), $value),
            $attribute instanceof OwningReference => $this->readOwned($value),
            $attribute instanceof Collection => $this->readItems($attribute, $value),
        };
    }

    private function readOwned(?object $value): ?OwnedNode {
        return $value === null ? null : new OwnedNode($value::class, $this->read($value));
    }

    /** @param array<mixed> $items */
    private function readItems(Collection $attribute, array $items): CollectionNode {
        return new CollectionNode(array_map(
            fn (mixed $item): ?ValueNode => match ($attribute->itemKind) {
                FieldKind::Scalar => new LeafNode(ScalarValues::toStorage($attribute->itemScalarType, $item)),
                FieldKind::ValueObject => new LeafNode($item === null ? null : StorableValues::toStorage($item)),
                FieldKind::Reference => new LeafNode($item === null ? null : ($this->referenceId)($item)),
                FieldKind::Embed => $item === null ? null : $this->readAs(new ReflectionClass($attribute->itemTarget), $item),
                FieldKind::OwningReference => $this->readOwned($item),
            },
            array_values($items),
        ));
    }

    /** The class $property is declared as: what an Embed is read through. */
    private static function declaredClassOf(ReflectionProperty $property): string {
        $type = $property->getType();

        // The reader already rejected anything but a single class.
        assert($type instanceof ReflectionNamedType);

        return $type->getName();
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityView;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeFields;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\CollectionNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\LeafNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ObjectNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\OwnedNode;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\Tree\ValueNode;

/**
 * Any entity whole, whatever its type's source: what code wanting the whole
 * entity works with, the admin's API, the schema editor, an export. Given by
 * PersistenceSession::loadDynamic(), one per entity.
 *
 * It's two parts, each field held by one. Its native view is the object of
 * its last native class, the very one load() gives: a field that class
 * declares is read from it. Its bag holds every other field: those of its
 * editor-created levels. A type with no native ancestor has no view, only a
 * bag; a native type's bag is empty.
 *
 * As an EntityView, it gives a value as a property holds it. An Embed or an
 * owned object comes back as the DynamicEntity standing for it, one per
 * object, wherever it's held: in the view or in the bag.
 */
final class DynamicEntity implements EntityView
{
    /** @var array<string, DynamicEntity> What stands for each Embed and owned object the bag holds, by path from this entity. */
    private array $held = [];

    /** @var array<string, ReflectionProperty>|null The view's fields, by name, once looked up. */
    private ?array $viewFields = null;

    /**
     * Made by DynamicEntities only.
     * @param ObjectNode $bag The values of every field the view's class
     *        doesn't declare, in stored form.
     * @param array<string, string> $ownedIds The id of each owned object the
     *        bag holds, by path from this entity.
     */
    public function __construct(
        private readonly DynamicEntities $entities,
        private readonly string $type,
        private readonly ?string $id,
        private readonly ?object $view,
        private readonly ObjectNode $bag,
        private readonly array $ownedIds,
    ) {
    }

    /** Its id; null for an Embed, or an entity never saved. */
    public function id(): ?string {
        return $this->id;
    }

    /** Its native view: the object of its last native class; null for a type with no native ancestor. */
    public function view(): ?object {
        return $this->view;
    }

    public function type(): string {
        return $this->type;
    }

    public function isA(string $type): bool {
        for ($current = $this->type; $current !== null; $current = $this->entities->schemas->get($current)->parent) {
            if ($current === $type) {
                return true;
            }
        }

        return false;
    }

    /** False too for a field the reader may not read: for it, there's nothing there. */
    public function has(string $path): bool {
        try {
            $this->get($path);
        } catch (InvalidArgumentException|PermissionDenied) {
            return false;
        }

        return true;
    }

    /**
     * @throws PermissionDenied For a field the reader may not read.
     */
    public function get(string $path): mixed {
        $rest = FieldPath::fromString($path);
        $name = $rest->head();
        $fields = $this->entities->schemas->hierarchyFieldsOf($this->type);
        if (is_int($name) || !$fields->has($name)) {
            throw $this->nothingAt($path);
        }

        $field = $fields->get($name);
        $value = $this->valueOf($field, $name);
        $next = $rest->tail();
        if ($next === null) {
            return $value;
        }

        $join = $rest->headJoin();
        if ($field->kind() === FieldKind::Collection && $join === FieldPath::TRAVERSE && is_int($next->head())) {
            if (!array_key_exists($next->head(), $value)) {
                throw $this->nothingAt($path);
            }

            $value = $value[$next->head()];
            $next = $next->tail();
            if ($next === null) {
                return $value;
            }
        } elseif (!(($field->kind() === FieldKind::Embed && $join === FieldPath::EMBED) || ($field->kind() === FieldKind::OwningReference && $join === FieldPath::TRAVERSE))) {
            throw $this->nothingAt($path);
        }

        if (!$value instanceof self) {
            throw $this->nothingAt($path);
        }

        return $value->get((string) $next);
    }

    /** The value of $field, named $name, as a property holds it. */
    private function valueOf(FieldDescriptor $field, FieldName $name): mixed {
        $property = $this->viewFields()[(string) $name] ?? null;
        if ($property !== null) {
            if (!$property->isInitialized($this->view)) {
                throw PermissionDenied::ofFields($this->type, [(string) $name]);
            }

            return $this->fromView($field, $property->getValue($this->view));
        }

        if (!$this->entities->permissions->canRead($this->declaringTypeOf($name), (string) $name)) {
            throw PermissionDenied::ofFields($this->type, [(string) $name]);
        }

        return $this->fromBag($field, $this->bag->find($name), (string) $name);
    }

    /** $value, as the view's property holds it, in the form get() gives. */
    private function fromView(FieldDescriptor $field, mixed $value): mixed {
        return match ($field->kind()) {
            FieldKind::Scalar, FieldKind::ValueObject => $value,
            FieldKind::Reference => $value === null ? null : $this->entities->idOf($value),
            FieldKind::Embed, FieldKind::OwningReference => $value === null ? null : $this->entities->of($value),
            FieldKind::Collection => array_map(fn (mixed $item): mixed => $this->fromView($field->itemDescriptor(), $item), array_values($value)),
        };
    }

    /** $node, stored in the bag at $at, in the form get() gives. */
    private function fromBag(FieldDescriptor $field, ?ValueNode $node, string $at): mixed {
        return match ($field->kind()) {
            FieldKind::Scalar => ScalarValues::fromStorage($field->scalarType(), $field->enumClass(), LeafNode::valueOf($node)),
            FieldKind::ValueObject => LeafNode::valueOf($node) === null ? null : $field->target()::fromStorage(LeafNode::valueOf($node)),
            FieldKind::Reference => LeafNode::valueOf($node),
            FieldKind::Embed => $this->held[$at] ??= $this->entities->inBag($field->target(), ObjectNode::from($node) ?? ObjectNode::empty(), null, DynamicEntities::under($this->ownedIds, $at)),
            FieldKind::OwningReference => OwnedNode::from($node) === null ? null : ($this->held[$at] ??= $this->entities->inBag(
                OwnedNode::from($node)->identifier,
                OwnedNode::from($node)->fields,
                $this->ownedIds[$at] ?? null,
                DynamicEntities::under($this->ownedIds, $at),
            )),
            FieldKind::Collection => array_map(
                fn (int $index): mixed => $this->fromBag($field->itemDescriptor(), CollectionNode::from($node)?->items[$index] ?? null, $at . FieldPath::TRAVERSE . $index),
                array_keys(CollectionNode::from($node)?->items ?? []),
            ),
        };
    }

    /**
     * The view's fields, by name: none without a view.
     * @return array<string, ReflectionProperty>
     */
    private function viewFields(): array {
        if ($this->viewFields === null) {
            $this->viewFields = [];
            if ($this->view !== null) {
                foreach (NativeFields::of(new ReflectionClass($this->view)) as $property) {
                    $this->viewFields[$property->getName()] = $property;
                }
            }
        }

        return $this->viewFields;
    }

    /** The type in this entity's chain declaring the field $name: what its read permission is asked of. */
    private function declaringTypeOf(FieldName $name): string {
        $schemas = $this->entities->schemas;
        for ($current = $this->type; $current !== null; $current = $schemas->get($current)->parent) {
            if ($schemas->get($current)->fields->has($name)) {
                return $current;
            }
        }

        return $this->type;
    }

    private function nothingAt(string $path): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('"%s" has no value at "%s".', $this->type, $path));
    }
}

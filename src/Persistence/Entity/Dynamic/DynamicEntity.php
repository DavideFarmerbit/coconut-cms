<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\EditRefused;
use XyloIsCoding\CoconutCms\Persistence\Entity\Edit\Setters;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityView;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;
use XyloIsCoding\CoconutCms\Persistence\Permission\PermissionDenied;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Setter;
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
    /** @var array<string, mixed> Each bag field's value in the form get() gives, by name, once read or set. */
    private array $values = [];

    /** @var list<string> Every path set on it, from it, in the order set. */
    private array $changed = [];

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
        private ?string $id,
        private readonly ?object $view,
        private readonly ObjectNode $bag,
        private readonly array $ownedIds,
    ) {
    }

    /** Its id; null for an Embed, or an entity never saved. */
    public function id(): ?string {
        return $this->id;
    }

    /**
     * Its bag, field by field, for the save writing it: each field's value
     * in the form get() gives; for a field the reader may not read, and
     * never set, what's stored, untouched.
     * @internal
     * @return array<string, array{FieldDescriptor, mixed, bool, ?ValueNode}> By name: its
     *         field, its value, whether it's kept as stored, and then what's stored.
     */
    public function bagFields(): array {
        $fields = $this->entities->schemas->hierarchyFieldsOf($this->type);
        $bag = [];
        foreach ($this->bag->names() as $name) {
            $field = $fields->get($name);
            $bag[(string) $name] = array_key_exists((string) $name, $this->values) || $this->entities->permissions->canRead($this->declaringTypeOf($name), (string) $name)
                ? [$field, $this->valueOf($field, $name), false, null]
                : [$field, null, true, $this->bag->find($name)];
        }

        return $bag;
    }

    /**
     * The ids of the owned objects stored at $at in the bag and under it,
     * by path from this entity: what a field the reader may not read keeps.
     * @internal
     * @return array<string, string>
     */
    public function heldIdsAt(string $at): array {
        return array_filter(
            $this->ownedIds,
            static fn (string $path): bool => $path === $at || str_starts_with($path, $at . FieldPath::TRAVERSE) || str_starts_with($path, $at . FieldPath::EMBED),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Takes the id its save gave it, the one it had or a new one, and
     * forgets the paths set: the save wrote them. Called by the save only.
     * @internal
     */
    public function saved(string $id): void {
        $this->id = $id;
        $this->changed = [];
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

    /**
     * Sets the value at $path, in the form get() gives: an Embed or an owned
     * object as an EntityView, a DynamicEntity or a native object; a
     * Reference as its target, in either form. A path into an Embed, an owned
     * object or a Collection's item sets it there. The value is checked here,
     * the way PHP checks a typed property. A field its native view's class
     * declares is set on the view, through its #[Setter] if it names one; any
     * other goes into the bag. Nothing is saved: saving is the session's.
     * @throws InvalidValue For a value its field can't hold, or a path leading to no field.
     * @throws ValidationFailed When a setter refuses the value.
     */
    public function set(string $path, mixed $value): void {
        $rest = FieldPath::fromString($path);
        $name = $rest->head();
        $fields = $this->entities->schemas->hierarchyFieldsOf($this->type);
        if (is_int($name) || !$fields->has($name)) {
            throw new InvalidValue($path, sprintf('a %s has no such field.', $this->type));
        }

        $field = $fields->get($name);
        $next = $rest->tail();
        if ($next === null) {
            $this->setField($field, $name, $value, $path);

            return;
        }

        $join = $rest->headJoin();
        if ($field->kind() === FieldKind::Collection && $join === FieldPath::TRAVERSE && is_int($next->head())) {
            $items = $this->get((string) $name);
            $index = $next->head();
            if (!array_key_exists($index, $items)) {
                throw new InvalidValue($path, sprintf('"%s" has no item %d.', $name, $index));
            }

            $after = $next->tail();
            if ($after === null) {
                $items[$index] = $value;
                $this->setField($field, $name, $items, $path);

                return;
            }

            $holder = $items[$index];
            $prefix = $name . FieldPath::TRAVERSE . $index;
            $join = FieldPath::TRAVERSE;
        } elseif (($field->kind() === FieldKind::Embed && $join === FieldPath::EMBED) || ($field->kind() === FieldKind::OwningReference && $join === FieldPath::TRAVERSE)) {
            $holder = $this->get((string) $name);
            $after = $next;
            $prefix = (string) $name;
        } else {
            throw new InvalidValue($path, sprintf('nothing of "%s" can be reached that way.', $name));
        }

        if (!$holder instanceof self) {
            throw new InvalidValue($path, 'it\'s empty: set what holds it first.');
        }

        // What the holder refuses is told by the path set() was given here.
        try {
            $holder->set((string) $after, $value);
        } catch (InvalidValue $invalid) {
            throw $invalid->under($prefix, $join);
        } catch (ValidationFailed $refused) {
            $errors = [];
            foreach ($refused->errors as $at => $messages) {
                $errors[$at === '' ? $prefix : $prefix . $join . $at] = $messages;
            }

            throw new ValidationFailed($this->type, $errors);
        }

        $this->changed[] = $path;
    }

    /**
     * Every path set on it, from it, in the order set: what afterEdit() is told.
     * @return list<string>
     */
    public function changed(): array {
        return $this->changed;
    }

    /** Sets $field, named $name, to $value, checked: on the view, or in the bag. */
    private function setField(FieldDescriptor $field, FieldName $name, mixed $value, string $path): void {
        $values = $this->entities->values;
        $values->check($field, $value, $path);

        $property = $this->viewFields()[(string) $name] ?? null;
        if ($property !== null) {
            if ($property->isReadOnly() && $property->getAttributes(Setter::class) === []) {
                throw new InvalidValue($path, 'it\'s readonly: only a #[Setter] could change it.');
            }

            try {
                Setters::assign($this->view, $property, $values->forView($field, $value, $path));
            } catch (EditRefused $refused) {
                throw new ValidationFailed($this->type, [$path => [$refused->getMessage()]]);
            }
        } else {
            $this->values[(string) $name] = $values->forBag($field, $value);
        }

        $this->changed[] = $path;
    }

    /** Forgets the paths set: once a save wrote them. Called by the save only. @internal */
    public function clearChanged(): void {
        $this->changed = [];
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

        if (!array_key_exists((string) $name, $this->values)) {
            $this->values[(string) $name] = $this->fromBag($field, $this->bag->find($name), (string) $name);
        }

        return $this->values[(string) $name];
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
            FieldKind::Embed => $this->entities->inBag($field->target(), ObjectNode::from($node) ?? ObjectNode::empty(), null, DynamicEntities::under($this->ownedIds, $at)),
            FieldKind::OwningReference => OwnedNode::from($node) === null ? null : $this->entities->inBag(
                OwnedNode::from($node)->identifier,
                OwnedNode::from($node)->fields,
                $this->ownedIds[$at] ?? null,
                DynamicEntities::under($this->ownedIds, $at),
            ),
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

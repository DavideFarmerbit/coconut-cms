<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Edit;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use SplObjectStorage;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;
use XyloIsCoding\CoconutCms\Persistence\Permission\Permissions;
use XyloIsCoding\CoconutCms\Persistence\Schema\Attribute\Property\Setter;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\DefaultInstances;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeEntityRegistry;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeFields;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\StoredForm;

/**
 * Edits a native entity by path, the way PHP code edits it: what a client
 * knowing no PHP class, the API, changes entities through. Each change is a
 * path and a value in stored form, the form a read gives:
 *
 *     ['name' => 'Racer', 'chassis:weight' => 1200, 'engine.horsepower' => 300,
 *      'engine' => ['type' => 'shop:turbo_engine', 'values' => ['boost' => 2]],
 *      'tags' => ['red', 'fast'], 'maker' => '01a1…']
 *
 * - A scalar or a value object as stored; a Reference as its target's id.
 * - An Embed as `{values}`, a new one from its type's defaults.
 * - An owned object as `{type, values}`, a new one from that type's
 *   defaults; with the id of the one already there, `{type, id, values}`
 *   keeps it and changes its values. Null empties the slot.
 * - A Collection as a whole list, each item in the same forms: an owned item
 *   with the id of one of its items keeps it.
 * - `values` maps paths from that object to values, in the same forms.
 *
 * Changes apply in order. Each value is turned into its PHP form, then given
 * to the field's #[Setter] if it names one, or set on the property. Then
 * every object changed implementing AfterEdit gets afterEdit(), the deepest
 * first. Saving is the caller's: nothing here writes.
 */
final class NativeEditor
{
    private readonly StoredForm $storedForm;

    /** @var array<class-string, array<string, ReflectionProperty>> Each class's fields, by name. */
    private array $fields = [];

    /**
     * Every object changed in the edit running, with its depth, its path
     * from the root, and the paths changed relative to it.
     * @var SplObjectStorage<object, array{int, string, list<string>}>
     */
    private SplObjectStorage $touched;

    /** The edited entity's type, for the errors a refusal reports. */
    private string $rootType = '';

    /**
     * @param Closure(string, string): object $reference The standalone entity
     *        a Reference set to an id points at, given the id and the change's
     *        path. Throws InvalidChange when there's none.
     * @param Closure(object): ?string $idOf The id of an object loaded or saved.
     */
    public function __construct(
        private readonly SchemaLookup $schemas,
        private readonly Permissions $permissions,
        private readonly NativeEntityRegistry $classes,
        private readonly Closure $reference,
        private readonly Closure $idOf,
    ) {
        $this->storedForm = new StoredForm($schemas);
    }

    /**
     * A new $type entity, from its type's defaults, with $changes applied.
     * Throws InvalidChange for a type with no native class: an
     * editor-created type is edited by editor saves.
     * @param array<string, mixed> $changes By path.
     */
    public function create(string $type, array $changes): object {
        if (!$this->classes->has($type)) {
            throw new InvalidChange('', sprintf('"%s" is editor-created: its entities are created through editor saves, not built yet.', $type));
        }

        $root = DefaultInstances::build(new ReflectionClass($this->classes->classOf($type)));
        $this->edit($root, $changes);

        return $root;
    }

    /**
     * Applies $changes to $root, then every afterEdit().
     * @param array<string, mixed> $changes By path.
     * @throws InvalidChange For a change it can't apply.
     * @throws ValidationFailed For a setter or afterEdit() throwing EditRefused.
     */
    public function edit(object $root, array $changes): void {
        $this->touched = new SplObjectStorage();
        $this->rootType = NativeIdentifiers::of($root::class);

        foreach ($changes as $path => $value) {
            $this->applyAt($root, self::parsed((string) $path, (string) $path), $value, '', FieldPath::TRAVERSE, 0);
        }

        $hooks = [];
        foreach ($this->touched as $object) {
            if ($object instanceof AfterEdit) {
                $hooks[] = [$object, ...$this->touched[$object]];
            }
        }

        // The deepest first: an owner sees what its owned objects' hooks did.
        usort($hooks, static fn (array $a, array $b): int => $b[1] <=> $a[1]);
        foreach ($hooks as [$object, , $at, $paths]) {
            try {
                $object->afterEdit($paths);
            } catch (EditRefused $refused) {
                throw new ValidationFailed($this->rootType, [$at => [$refused->getMessage()]]);
            }
        }
    }

    /**
     * Applies one change: $value at $path from $object, which sits at $prefix
     * in the aggregate, joined to its fields by $join.
     */
    private function applyAt(object $object, FieldPath $path, mixed $value, string $prefix, string $join, int $depth): void {
        $this->record($object, $depth, $prefix, (string) $path);
        $full = self::joined($prefix, $join, $path);

        $head = $path->head();
        if (is_int($head)) {
            throw new InvalidChange($full, 'an index follows a Collection, never an object.');
        }

        $property = $this->property($object, (string) $head, $full);
        $here = self::joined($prefix, $join, FieldPath::of($head));
        $field = $this->descriptor($object, $head);
        $tail = $path->tail();
        if ($tail === null) {
            $this->assign($object, $property, $this->converted($field, $value, $here, $property->getValue($object), $depth), $here);

            return;
        }

        $current = $property->getValue($object);
        match ($field->kind()) {
            FieldKind::Embed => $path->headJoin() === FieldPath::EMBED
                ? $this->applyAt($current, $tail, $value, $here, FieldPath::EMBED, $depth + 1)
                : throw new InvalidChange($full, sprintf('"%s" is an Embed: its fields follow "%s".', $head, FieldPath::EMBED)),
            FieldKind::OwningReference => match (true) {
                $path->headJoin() !== FieldPath::TRAVERSE => throw new InvalidChange($full, sprintf('"%s" holds an owned object: its fields follow "%s".', $head, FieldPath::TRAVERSE)),
                $current === null => throw new InvalidChange($full, sprintf('"%s" is empty: set it to {"type": ...} first.', $here)),
                default => $this->applyAt($current, $tail, $value, $here, FieldPath::TRAVERSE, $depth + 1),
            },
            FieldKind::Collection => $this->applyInCollection($object, $property, $field, $current, $tail, $value, $here, $full, $depth),
            FieldKind::Reference => throw new InvalidChange($full, sprintf('"%s" is a Reference: its target is edited on its own.', $here)),
            default => throw new InvalidChange($full, sprintf('"%s" holds a value: nothing follows it.', $here)),
        };
    }

    /**
     * Applies a change under a Collection: $rest starts with an item's
     * index. Replacing an item gives the setter the whole new list.
     * @param list<mixed> $items The Collection's current items.
     */
    private function applyInCollection(object $object, ReflectionProperty $property, FieldDescriptor $field, array $items, FieldPath $rest, mixed $value, string $here, string $full, int $depth): void {
        $index = $rest->head();
        if (!is_int($index)) {
            throw new InvalidChange($full, sprintf('"%s" is a Collection: an item\'s index follows it.', $here));
        }

        if (!array_key_exists($index, $items)) {
            throw new InvalidChange($full, sprintf('"%s" has no item %d.', $here, $index));
        }

        $itemAt = $here . FieldPath::TRAVERSE . $index;
        $after = $rest->tail();
        if ($after === null) {
            $items[$index] = $this->convertedItem($field->itemDescriptor(), $value, $itemAt, $items, $depth + 1);
            $this->assign($object, $property, $items, $here);

            return;
        }

        if (!is_object($items[$index])) {
            throw new InvalidChange($full, sprintf('"%s" holds %s: nothing follows it.', $itemAt, $items[$index] === null ? 'nothing' : 'a value'));
        }

        if ($field->itemDescriptor()->kind() === FieldKind::Reference) {
            throw new InvalidChange($full, sprintf('"%s" is a Reference: its target is edited on its own.', $itemAt));
        }

        $this->applyAt($items[$index], $after, $value, $itemAt, FieldPath::TRAVERSE, $depth + 2);
    }

    /**
     * Gives $field's new value to its setter, or sets the property.
     * @throws ValidationFailed When the setter throws EditRefused.
     */
    private function assign(object $object, ReflectionProperty $property, mixed $value, string $at): void {
        if ($property->isReadOnly() && $property->getAttributes(Setter::class) === []) {
            throw new InvalidChange($at, sprintf('"%s" is readonly: only a #[Setter] could change it.', $at));
        }

        try {
            Setters::assign($object, $property, $value);
        } catch (EditRefused $refused) {
            throw new ValidationFailed($this->rootType, [$at => [$refused->getMessage()]]);
        }
    }

    /**
     * $value, given in stored form for $field, in the form its property holds.
     * @param mixed $current What the field holds now: an owned object kept by id comes from it.
     */
    private function converted(FieldDescriptor $field, mixed $value, string $at, mixed $current, int $depth): mixed {
        return match ($field->kind()) {
            FieldKind::Collection => $this->convertedItems($field->itemDescriptor(), $value, $at, is_array($current) ? $current : [], $depth),
            FieldKind::OwningReference => $this->owned($field, $value, $at, $current === null ? [] : [$current], $depth),
            default => $this->convertedItem($field, $value, $at, [], $depth),
        };
    }

    /**
     * A Collection's new items, each converted.
     * @param list<mixed> $current Its items now.
     * @return list<mixed>
     */
    private function convertedItems(FieldDescriptor $item, mixed $value, string $at, array $current, int $depth): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidChange($at, sprintf('"%s" is a Collection: it\'s set to a list.', $at));
        }

        $items = [];
        foreach ($value as $index => $itemValue) {
            $items[] = $this->convertedItem($item, $itemValue, $at . FieldPath::TRAVERSE . $index, $current, $depth + 1);
        }

        return $items;
    }

    /**
     * One value of $field, or one item of a Collection whose items $field
     * describes, converted.
     * @param list<mixed> $candidates The owned objects an id may keep.
     */
    private function convertedItem(FieldDescriptor $field, mixed $value, string $at, array $candidates, int $depth): mixed {
        return match ($field->kind()) {
            FieldKind::Scalar => ScalarValues::fromStorage($field->scalarType(), $field->enumClass(), $this->checked($field, $value, $at)),
            FieldKind::ValueObject => ($stored = $this->checked($field, $value, $at)) === null ? null : $field->target()::fromStorage($stored),
            FieldKind::Reference => $this->referenced($field, $value, $at),
            FieldKind::Embed => $this->embedded($field->target(), $value, $at, $depth),
            FieldKind::OwningReference => $this->owned($field, $value, $at, $candidates, $depth),
            FieldKind::Collection => throw new InvalidChange($at, 'a Collection holds no Collection.'),
        };
    }

    /** $value, checked as $field stores it, in canonical form. */
    private function checked(FieldDescriptor $field, mixed $value, string $at): mixed {
        try {
            return $this->storedForm->checkedValue($field, $value, $at);
        } catch (InvalidArgumentException $invalid) {
            throw new InvalidChange($at, $invalid->getMessage());
        }
    }

    /** The entity a Reference set to the id $value points at; null for null. */
    private function referenced(FieldDescriptor $field, mixed $value, string $at): ?object {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidChange($at, sprintf('"%s" is a Reference: it\'s set to an entity\'s id, or null.', $at));
        }

        $target = ($this->reference)($value, $at);
        $class = $this->classes->classOf($field->target());
        if (!$target instanceof $class) {
            throw new InvalidChange($at, sprintf('"%s" points at a %s or a type extending it; %s is a %s.', $at, $field->target(), $value, NativeIdentifiers::of($target::class)));
        }

        return $target;
    }

    /** A new Embed of $type, from its defaults, $value's values applied. */
    private function embedded(string $type, mixed $value, string $at, int $depth): object {
        [$given, $id, $values] = self::objectForm($value, $at);
        if (($given !== null && $given !== $type) || $id !== null) {
            throw new InvalidChange($at, sprintf('"%s" is an Embed of %s: it\'s set to {"values": {...}}, with no other type and no id.', $at, $type));
        }

        $embed = DefaultInstances::build(new ReflectionClass($this->classes->classOf($type)));
        $this->applyValues($embed, $values, $at, FieldPath::EMBED, $depth + 1);

        return $embed;
    }

    /**
     * The owned object $value asks for: null, one of $candidates kept by its
     * id, or a new one of the type it names, from its defaults.
     * @param list<mixed> $candidates
     */
    private function owned(FieldDescriptor $field, mixed $value, string $at, array $candidates, int $depth): ?object {
        if ($value === null) {
            return $field->allowsNull() ? null : throw new InvalidChange($at, sprintf('"%s" can\'t be empty.', $at));
        }

        [$type, $id, $values] = self::objectForm($value, $at);
        if ($type === null) {
            throw new InvalidChange($at, sprintf('"%s" holds an owned object: it\'s set to {"type": ...}.', $at));
        }

        if (!$this->schemas->has($type) || !$this->classes->has($type)) {
            throw new InvalidChange($at, sprintf('"%s": %s', $at, $this->schemas->has($type) ? sprintf('"%s" is editor-created: editor saves, not built yet, create its entities.', $type) : sprintf('there is no type "%s".', $type)));
        }

        $class = $this->classes->classOf($type);
        if (!is_a($class, $this->classes->classOf($field->target()), true)) {
            throw new InvalidChange($at, sprintf('"%s" holds a %s or a type extending it, not a %s.', $at, $field->target(), $type));
        }

        $kept = null;
        if ($id !== null) {
            foreach ($candidates as $candidate) {
                if (is_object($candidate) && ($this->idOf)($candidate) === $id) {
                    $kept = $candidate;
                }
            }

            if ($kept === null) {
                throw new InvalidChange($at, sprintf('"%s" holds no owned object %s to keep.', $at, $id));
            }

            if ($kept::class !== $class) {
                throw new InvalidChange($at, sprintf('owned object %s is a %s: it keeps its type.', $id, NativeIdentifiers::of($kept::class)));
            }
        }

        $owned = $kept ?? DefaultInstances::build(new ReflectionClass($class));
        $this->applyValues($owned, $values, $at, FieldPath::TRAVERSE, $depth + 1);

        return $owned;
    }

    /**
     * Applies $values, changes by path from $object, which sits at $at.
     * @param array<string, mixed> $values
     */
    private function applyValues(object $object, array $values, string $at, string $join, int $depth): void {
        foreach ($values as $path => $value) {
            $full = $at . $join . $path;
            $this->applyAt($object, self::parsed((string) $path, $full), $value, $at, $join, $depth);
        }
    }

    /** $object's field $name, once its reader may read it: one it may not is one it doesn't know. */
    private function property(object $object, string $name, string $full): ReflectionProperty {
        $class = $object::class;
        $this->fields[$class] ??= array_column(
            array_map(static fn (ReflectionProperty $property): array => [$property->getName(), $property], NativeFields::of(new ReflectionClass($class))),
            1,
            0,
        );

        $type = NativeIdentifiers::of($class);
        $property = $this->fields[$class][$name] ?? null;
        if ($property === null || !$this->permissions->canRead($type, $name)) {
            throw new InvalidChange($full, sprintf('"%s" has no field "%s".', $type, $name));
        }

        return $property;
    }

    /** The descriptor of $object's field $name. */
    private function descriptor(object $object, FieldName $name): FieldDescriptor {
        return $this->schemas->hierarchyFieldsOf(NativeIdentifiers::of($object::class))->get($name);
    }

    /** Notes that $path, from $object, changed. */
    private function record(object $object, int $depth, string $at, string $path): void {
        $entry = $this->touched->offsetExists($object) ? $this->touched[$object] : [$depth, $at, []];
        $entry[2][] = $path;
        $this->touched[$object] = $entry;
    }

    /**
     * What an Embed, an owned object or an item is set to, read: its type,
     * its id, its values by path. Each may be missing.
     * @return array{?string, ?string, array<string, mixed>}
     */
    private static function objectForm(mixed $value, string $at): array {
        $form = sprintf('"%s" holds an object: it\'s set to {"type": ..., "id": ..., "values": {...}}, each one optional.', $at);
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || array_diff(array_keys($value), ['type', 'id', 'values']) !== []) {
            throw new InvalidChange($at, $form);
        }

        $type = $value['type'] ?? null;
        $id = $value['id'] ?? null;
        $values = $value['values'] ?? [];
        if (($type !== null && !is_string($type)) || ($id !== null && !is_string($id)) || !is_array($values) || ($values !== [] && array_is_list($values))) {
            throw new InvalidChange($at, $form);
        }

        return [$type, $id, $values];
    }

    /** $path parsed; $full names it in an error. */
    private static function parsed(string $path, string $full): FieldPath {
        try {
            return FieldPath::fromString($path);
        } catch (InvalidArgumentException $invalid) {
            throw new InvalidChange($full, $invalid->getMessage());
        }
    }

    /** $path from an object at $prefix, joined by $join, as a path from the root. */
    private static function joined(string $prefix, string $join, FieldPath $path): string {
        return $prefix === '' ? (string) $path : $prefix . $join . $path;
    }
}

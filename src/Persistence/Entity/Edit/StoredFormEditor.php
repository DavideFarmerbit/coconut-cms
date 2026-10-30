<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Edit;

use Closure;
use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic\DynamicEntities;
use XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic\DynamicEntity;
use XyloIsCoding\CoconutCms\Persistence\Entity\Dynamic\InvalidValue;
use XyloIsCoding\CoconutCms\Persistence\Entity\ValidationFailed;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\StoredForm;

/**
 * Edits an entity by path, given changes in stored form: what a client
 * knowing no PHP class, the API, changes entities through. It works on a
 * DynamicEntity, so any entity, native or editor-created, edits alike. Each
 * change is a path and a value in stored form, the form a read gives:
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
 * Changes apply in order. Each value is turned into its PHP form and given to
 * DynamicEntity::set(), which checks it and calls the field's #[Setter] if
 * it names one. afterEdit() runs when the entity is saved; saving is the
 * caller's: nothing here writes.
 */
final readonly class StoredFormEditor
{
    private StoredForm $storedForm;

    /**
     * @param Closure(string, string): DynamicEntity $reference The standalone
     *        entity a Reference set to an id points at, given the id and the
     *        change's path. Throws InvalidChange when there's none.
     */
    public function __construct(
        private SchemaLookup $schemas,
        private DynamicEntities $entities,
        private Closure $reference,
    ) {
        $this->storedForm = new StoredForm($schemas);
    }

    /**
     * A new $type entity, of any type, from its type's defaults, with $changes applied.
     * @param array<string, mixed> $changes By path.
     */
    public function create(string $type, array $changes): DynamicEntity {
        $root = $this->entities->create($type);
        $this->edit($root, $changes);

        return $root;
    }

    /**
     * Applies $changes to $root.
     * @param array<string, mixed> $changes By path.
     * @throws InvalidChange For a change it can't apply.
     * @throws ValidationFailed For a setter throwing EditRefused.
     */
    public function edit(DynamicEntity $root, array $changes): void {
        $this->applyValues($root, $changes, '', FieldPath::TRAVERSE);
    }

    /**
     * Applies $values, changes by path from $entity, which sits at $at in
     * the edit, joined to its fields by $join: what errors name.
     * @param array<string, mixed> $values
     */
    private function applyValues(DynamicEntity $entity, array $values, string $at, string $join): void {
        foreach ($values as $path => $value) {
            $full = self::joined($at, $join, (string) $path);
            $this->applyAt($entity, $entity, self::parsed((string) $path, $full), '', FieldPath::TRAVERSE, $value, $at, $join);
        }
    }

    /**
     * Applies one change: $value at $path from $entity, which sits at
     * $relative from $root, joined to its fields by $join. $root is what
     * set() is called on, so each entity on the way records what changed
     * under it; it sits at $rootAt in the edit, joined by $rootJoin.
     */
    private function applyAt(DynamicEntity $root, DynamicEntity $entity, FieldPath $path, string $relative, string $join, mixed $value, string $rootAt, string $rootJoin): void {
        $here = self::joined($relative, $join, (string) $path->head());
        $full = self::joined($rootAt, $rootJoin, $relative === '' ? (string) $path : $relative . $join . $path);
        $named = self::joined($rootAt, $rootJoin, $here);

        $head = $path->head();
        if (is_int($head)) {
            throw new InvalidChange($full, 'an index follows a Collection, never an object.');
        }

        if (!$entity->has((string) $head)) {
            throw new InvalidChange($full, sprintf('"%s" has no field "%s".', $entity->type(), $head));
        }

        $field = $this->schemas->hierarchyFieldsOf($entity->type())->get($head);
        $current = $entity->get((string) $head);
        $tail = $path->tail();
        if ($tail === null) {
            $this->set($root, $here, $this->converted($field, $value, $named, $current), $rootAt, $rootJoin);

            return;
        }

        match ($field->kind()) {
            FieldKind::Embed => $path->headJoin() === FieldPath::EMBED
                ? $this->applyAt($root, $current, $tail, $here, FieldPath::EMBED, $value, $rootAt, $rootJoin)
                : throw new InvalidChange($full, sprintf('"%s" is an Embed: its fields follow "%s".', $head, FieldPath::EMBED)),
            FieldKind::OwningReference => match (true) {
                $path->headJoin() !== FieldPath::TRAVERSE => throw new InvalidChange($full, sprintf('"%s" holds an owned object: its fields follow "%s".', $head, FieldPath::TRAVERSE)),
                $current === null => throw new InvalidChange($full, sprintf('"%s" is empty: set it to {"type": ...} first.', $named)),
                default => $this->applyAt($root, $current, $tail, $here, FieldPath::TRAVERSE, $value, $rootAt, $rootJoin),
            },
            FieldKind::Collection => $this->applyInCollection($root, $field, $current, $tail, $here, $named, $full, $value, $rootAt, $rootJoin),
            FieldKind::Reference => throw new InvalidChange($full, sprintf('"%s" is a Reference: its target is edited on its own.', $named)),
            default => throw new InvalidChange($full, sprintf('"%s" holds a value: nothing follows it.', $named)),
        };
    }

    /**
     * Applies a change under a Collection: $rest starts with an item's index.
     * @param list<mixed> $items The Collection's current items.
     */
    private function applyInCollection(DynamicEntity $root, FieldDescriptor $field, array $items, FieldPath $rest, string $here, string $named, string $full, mixed $value, string $rootAt, string $rootJoin): void {
        $index = $rest->head();
        if (!is_int($index)) {
            throw new InvalidChange($full, sprintf('"%s" is a Collection: an item\'s index follows it.', $named));
        }

        if (!array_key_exists($index, $items)) {
            throw new InvalidChange($full, sprintf('"%s" has no item %d.', $named, $index));
        }

        $itemHere = $here . FieldPath::TRAVERSE . $index;
        $itemNamed = $named . FieldPath::TRAVERSE . $index;
        $after = $rest->tail();
        if ($after === null) {
            $this->set($root, $itemHere, $this->convertedItem($field->itemDescriptor(), $value, $itemNamed, $items), $rootAt, $rootJoin);

            return;
        }

        if (!$items[$index] instanceof DynamicEntity) {
            throw new InvalidChange($full, sprintf('"%s" holds %s: nothing follows it.', $itemNamed, $items[$index] === null ? 'nothing' : 'a value'));
        }

        if ($field->itemDescriptor()->kind() === FieldKind::Reference) {
            throw new InvalidChange($full, sprintf('"%s" is a Reference: its target is edited on its own.', $itemNamed));
        }

        $this->applyAt($root, $items[$index], $after, $itemHere, FieldPath::TRAVERSE, $value, $rootAt, $rootJoin);
    }

    /** Sets $value at $path from $root; a value it can't hold is a change it can't apply. */
    private function set(DynamicEntity $root, string $path, mixed $value, string $rootAt, string $rootJoin): void {
        try {
            $root->set($path, $value);
        } catch (InvalidValue $invalid) {
            throw new InvalidChange(self::joined($rootAt, $rootJoin, $invalid->path), $invalid->reason);
        } catch (ValidationFailed $refused) {
            if ($rootAt === '') {
                throw $refused;
            }

            $errors = [];
            foreach ($refused->errors as $at => $messages) {
                $errors[self::joined($rootAt, $rootJoin, (string) $at)] = $messages;
            }

            throw new ValidationFailed($refused->identifier, $errors);
        }
    }

    /**
     * $value, given in stored form for $field, in the form get() gives.
     * @param mixed $current What the field holds now: an owned object kept by id comes from it.
     */
    private function converted(FieldDescriptor $field, mixed $value, string $at, mixed $current): mixed {
        return match ($field->kind()) {
            FieldKind::Collection => $this->convertedItems($field->itemDescriptor(), $value, $at, is_array($current) ? $current : []),
            FieldKind::OwningReference => $this->owned($field, $value, $at, $current === null ? [] : [$current]),
            default => $this->convertedItem($field, $value, $at, []),
        };
    }

    /**
     * A Collection's new items, each converted.
     * @param list<mixed> $current Its items now.
     * @return list<mixed>
     */
    private function convertedItems(FieldDescriptor $item, mixed $value, string $at, array $current): array {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidChange($at, sprintf('"%s" is a Collection: it\'s set to a list.', $at));
        }

        $items = [];
        foreach ($value as $index => $itemValue) {
            $items[] = $this->convertedItem($item, $itemValue, $at . FieldPath::TRAVERSE . $index, $current);
        }

        return $items;
    }

    /**
     * One value of $field, or one item of a Collection whose items $field
     * describes, converted.
     * @param list<mixed> $candidates The owned objects an id may keep.
     */
    private function convertedItem(FieldDescriptor $field, mixed $value, string $at, array $candidates): mixed {
        return match ($field->kind()) {
            FieldKind::Scalar => ScalarValues::fromStorage($field->scalarType(), $field->enumClass(), $this->checked($field, $value, $at)),
            FieldKind::ValueObject => ($stored = $this->checked($field, $value, $at)) === null ? null : $field->target()::fromStorage($stored),
            FieldKind::Reference => $this->referenced($field, $value, $at),
            FieldKind::Embed => $this->embedded($field->target(), $value, $at),
            FieldKind::OwningReference => $this->owned($field, $value, $at, $candidates),
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
    private function referenced(FieldDescriptor $field, mixed $value, string $at): ?DynamicEntity {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidChange($at, sprintf('"%s" is a Reference: it\'s set to an entity\'s id, or null.', $at));
        }

        $target = ($this->reference)($value, $at);
        if (!$target->isA($field->target())) {
            throw new InvalidChange($at, sprintf('"%s" points at a %s or a type extending it; %s is a %s.', $at, $field->target(), $value, $target->type()));
        }

        return $target;
    }

    /** A new Embed of $type, from its defaults, $value's values applied. */
    private function embedded(string $type, mixed $value, string $at): DynamicEntity {
        [$given, $id, $values] = self::objectForm($value, $at);
        if (($given !== null && $given !== $type) || $id !== null) {
            throw new InvalidChange($at, sprintf('"%s" is an Embed of %s: it\'s set to {"values": {...}}, with no other type and no id.', $at, $type));
        }

        $embed = $this->entities->create($type);
        $this->applyValues($embed, $values, $at, FieldPath::EMBED);

        return $embed;
    }

    /**
     * The owned object $value asks for: null, one of $candidates kept by its
     * id, or a new one of the type it names, from its defaults.
     * @param list<mixed> $candidates
     */
    private function owned(FieldDescriptor $field, mixed $value, string $at, array $candidates): ?DynamicEntity {
        if ($value === null) {
            return $field->allowsNull() ? null : throw new InvalidChange($at, sprintf('"%s" can\'t be empty.', $at));
        }

        [$type, $id, $values] = self::objectForm($value, $at);
        if ($type === null) {
            throw new InvalidChange($at, sprintf('"%s" holds an owned object: it\'s set to {"type": ...}.', $at));
        }

        if (!$this->schemas->has($type)) {
            throw new InvalidChange($at, sprintf('"%s": there is no type "%s".', $at, $type));
        }

        if (!$this->isA($type, $field->target())) {
            throw new InvalidChange($at, sprintf('"%s" holds a %s or a type extending it, not a %s.', $at, $field->target(), $type));
        }

        $kept = null;
        if ($id !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate instanceof DynamicEntity && $candidate->id() === $id) {
                    $kept = $candidate;
                }
            }

            if ($kept === null) {
                throw new InvalidChange($at, sprintf('"%s" holds no owned object %s to keep.', $at, $id));
            }

            if ($kept->type() !== $type) {
                throw new InvalidChange($at, sprintf('owned object %s is a %s: it keeps its type.', $id, $kept->type()));
            }
        }

        $owned = $kept ?? $this->entities->create($type);
        $this->applyValues($owned, $values, $at, FieldPath::TRAVERSE);

        return $owned;
    }

    private function isA(string $type, string $ancestor): bool {
        for ($current = $type; $current !== null; $current = $this->schemas->get($current)->parent) {
            if ($current === $ancestor) {
                return true;
            }
        }

        return false;
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

    /** $path under $prefix, joined by $join; $path alone under nothing. */
    private static function joined(string $prefix, string $join, string $path): string {
        return $prefix === '' ? $path : $prefix . $join . $path;
    }
}

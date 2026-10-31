<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Normalize;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\AddField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\CreateType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\DeleteType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\InheritDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\MakeRequired;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RemoveField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RenameType;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\RetypeField;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations\SetDefault;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Turns the operations an admin made, every undone step included, into the
 * fewest that end the same: what an editor save runs, and what its preview
 * shows. Renames chain, and one back where it started is none. What the save
 * adds takes in what's done to it after; added then removed is nothing. A
 * default keeps its last change, and one back to what's stored is none. An
 * existing field removed and added back holding the same is nothing; holding
 * something else, it's reset. One retyped back to what it held is nothing.
 *
 * It follows every type and field by identity, replays the operations on
 * them, and gives what's left in an order where each finds what it names:
 * deletes, type renames, new types, each type's field changes, then default
 * changes, with the final names.
 */
final class OperationNormalizer
{
    /** @var array<string, TypeTrack> Every editor-created type now, keyed by its name now. */
    private array $byName = [];

    /** @var list<TypeTrack> Every editor-created type, stored or new, in the order they appeared. */
    private array $types = [];

    /** @var array<string, DefaultTrack> The last default change at each path, in the order they appeared. */
    private array $defaults = [];

    /** @var array<int, DefaultValue|null> The default the last field change given pins, keyed by the field's object id. */
    private array $pinnedByChange = [];

    /**
     * @param array<string, EntitySchema> $native Every native type, keyed by identifier.
     * @param array<string, EntitySchema> $editor Every editor-created type, keyed by identifier.
     * @param array<string, array<string, mixed>> $ownDefaults What each
     *        editor-created type sets itself, keyed by identifier, then path.
     */
    public function __construct(
        private readonly array $native,
        private readonly array $editor,
        private readonly array $ownDefaults,
    ) {
    }

    /**
     * $operations normalized. Throws InvalidOperation, as a save does, when
     * one can't apply in the order given.
     * @param list<SchemaOperation> $operations
     * @return list<SchemaOperation>
     */
    public function normalize(array $operations): array {
        $check = new SchemaDraft($this->native, $this->editor);
        foreach ($operations as $index => $operation) {
            try {
                $operation->applyTo($check);
            } catch (InvalidOperation $refused) {
                throw InvalidOperation::at($index, $refused);
            }
        }

        $this->start();
        foreach ($operations as $operation) {
            $this->replay($operation);
        }

        return [...$this->deletes(), ...$this->typeRenames(), ...$this->creates(), ...$this->fieldChanges(), ...$this->defaultChanges()];
    }

    /** Follows every stored editor-created type and its own fields. */
    private function start(): void {
        $this->byName = [];
        $this->types = [];
        $this->defaults = [];
        $this->pinnedByChange = [];
        foreach ($this->editor as $identifier => $schema) {
            $type = new TypeTrack($identifier, $identifier, $schema->parent);
            foreach ($schema->fields as $field) {
                $type->addOriginal($field);
            }

            $this->byName[$identifier] = $type;
            $this->types[] = $type;
        }

        foreach ($this->types as $type) {
            if (is_string($type->parent)) {
                $type->parent = $this->byName[$type->parent] ?? $type->parent;
            }
        }
    }

    /** Replays $operation on what it names. */
    private function replay(SchemaOperation $operation): void {
        match (true) {
            $operation instanceof CreateType => $this->create($operation),
            $operation instanceof RenameType => $this->renameType($operation),
            $operation instanceof DeleteType => $this->delete($operation),
            $operation instanceof AddField => $this->addField($operation),
            $operation instanceof RemoveField => $this->removeField($operation),
            $operation instanceof RenameField => $this->renameField($operation),
            $operation instanceof MakeRequired => $this->changeField($operation->type, (string) $operation->field, $operation),
            $operation instanceof RetypeField => $this->changeField($operation->type, $operation->field->name(), $operation),
            $operation instanceof SetDefault => $this->changeDefault($operation->type, $operation->path, $operation->value),
            $operation instanceof InheritDefault => $this->changeDefault($operation->type, $operation->path, null),
            default => throw new LogicException(sprintf('%s can\'t be normalized.', $operation::class)),
        };
    }

    private function create(CreateType $operation): void {
        $type = new TypeTrack(null, $operation->type, $operation->parent === null ? null : ($this->byName[$operation->parent] ?? $operation->parent));
        $this->byName[$operation->type] = $type;
        $this->types[] = $type;
    }

    private function renameType(RenameType $operation): void {
        $type = $this->byName[$operation->old];
        unset($this->byName[$operation->old]);
        $type->name = $operation->new;
        $this->byName[$operation->new] = $type;
    }

    private function delete(DeleteType $operation): void {
        $this->byName[$operation->type]->deleted = true;
        unset($this->byName[$operation->type]);
    }

    /** A new field; or a stored one the save removed, added back: it takes up its values again. */
    private function addField(AddField $operation): void {
        $type = $this->byName[$operation->type];
        $name = $operation->field->name();
        $removed = $type->removed[$name] ?? null;
        if ($removed === null) {
            $type->add(new FieldTrack(null, null, $name, $operation->field, $operation->default, $operation->initializer, $operation));

            return;
        }

        unset($type->removed[$name]);
        $removed->removed = false;
        $removed->descriptor = $operation->field;
        $removed->changes[] = $operation;
        $type->fields[$name] = $removed;
        if ($operation->default !== null) {
            $this->changeDefault($operation->type, $name, $operation->default);
        }
    }

    private function removeField(RemoveField $operation): void {
        $type = $this->byName[$operation->type];
        $field = $type->fields[(string) $operation->field];
        unset($type->fields[(string) $operation->field]);
        $field->removed = true;
        if (!$field->isNew()) {
            $type->removed[(string) $operation->field] = $field;
        }
    }

    private function renameField(RenameField $operation): void {
        $type = $this->byName[$operation->type];
        $field = $type->fields[(string) $operation->old];
        unset($type->fields[(string) $operation->old]);
        $field->name = (string) $operation->new;
        $field->descriptor = $field->descriptor->renamed((string) $operation->new);
        $type->fields[(string) $operation->new] = $field;
    }

    /** $operation changing what field $name of $type holds. */
    private function changeField(string $type, string $name, RetypeField|MakeRequired $operation): void {
        $field = $this->byName[$type]->fields[$name];
        $field->changes[] = $operation;
        if ($operation instanceof MakeRequired) {
            $field->descriptor = $field->descriptor->withNullability(Nullability::NotNullable);

            return;
        }

        $field->descriptor = $operation->field;
        if ($field->isNew() && $operation->default !== null) {
            $field->default = $operation->default;
        }
    }

    /**
     * A default change at $path of $type: pinning $value, or inheriting when
     * null. One on a new field's own path changes the default it's added with.
     */
    private function changeDefault(string $typeName, string $path, ?DefaultValue $value): void {
        $type = $this->byName[$typeName];
        $head = (string) FieldPath::fromString($path)->head();
        $rest = substr($path, strlen($head));
        $field = $this->fieldNamed($type, $head);
        if ($field instanceof FieldTrack && $field->isNew() && $rest === '' && $value !== null && ($type->fields[$head] ?? null) === $field) {
            $field->default = $value;

            return;
        }

        $key = sprintf('%d|%s|%s', spl_object_id($type), $field instanceof FieldTrack ? spl_object_id($field) : $field, $rest);
        if (isset($this->defaults[$key])) {
            $this->defaults[$key]->value = $value;
        } else {
            $this->defaults[$key] = new DefaultTrack($type, $field, $rest, $value);
        }
    }

    /** Field $name of $type: its own or an editor-created ancestor's, followed; a native ancestor's, by name. */
    private function fieldNamed(TypeTrack $type, string $name): FieldTrack|string {
        for ($level = $type; $level instanceof TypeTrack; $level = $level->parent) {
            if (isset($level->fields[$name])) {
                return $level->fields[$name];
            }
        }

        return $name;
    }

    /** @return list<SchemaOperation> Every stored type the save deletes. */
    private function deletes(): array {
        $operations = [];
        foreach ($this->types as $type) {
            if (!$type->isNew() && $type->deleted) {
                $operations[] = new DeleteType($type->original);
            }
        }

        return $operations;
    }

    /** @return list<SchemaOperation> Every stored type renamed, each once its new name is free. */
    private function typeRenames(): array {
        $taken = [];
        $pending = [];
        foreach ($this->types as $type) {
            if (!$type->isNew() && !$type->deleted) {
                $taken[$type->original] = true;
                if ($type->name !== $type->original) {
                    $pending[] = [$type->original, $type->name];
                }
            }
        }

        return array_map(
            static fn (array $rename): SchemaOperation => new RenameType($rename[0], $rename[1]),
            self::ordered($pending, $taken, 'Types'),
        );
    }

    /** @return list<SchemaOperation> Every new type, as it ends up, each after its new parent. */
    private function creates(): array {
        $operations = [];
        $done = [];
        $left = array_values(array_filter($this->types, static fn (TypeTrack $type): bool => $type->isNew() && !$type->deleted));
        while ($left !== []) {
            foreach ($left as $index => $type) {
                $parent = $type->parent;
                if ($parent instanceof TypeTrack && $parent->isNew() && !isset($done[spl_object_id($parent)])) {
                    continue;
                }

                $operations[] = new CreateType($type->name, $type->parentName());
                $done[spl_object_id($type)] = true;
                unset($left[$index]);
            }
        }

        return $operations;
    }

    /** @return list<SchemaOperation> Every type's field changes: removed, renamed, changed, then added. */
    private function fieldChanges(): array {
        $operations = [];
        foreach ($this->types as $type) {
            if ($type->deleted) {
                continue;
            }

            $taken = [];
            $renames = [];
            foreach ($type->all as $field) {
                if ($field->isNew()) {
                    continue;
                }

                if ($field->removed) {
                    $operations[] = new RemoveField($type->name, $field->original);
                    continue;
                }

                $taken[$field->original] = true;
                if ($field->name !== $field->original) {
                    $renames[] = [$field->original, $field->name];
                }
            }

            foreach (self::ordered($renames, $taken, sprintf('Fields of "%s"', $type->name)) as [$old, $new]) {
                $operations[] = new RenameField($type->name, $old, $new);
            }

            foreach ($type->all as $field) {
                if (!$field->isNew() && !$field->removed && !$field->holdsWhatItHeld()) {
                    array_push($operations, ...$this->changesOf($type, $field, $field->originalDescriptor->renamed($field->name)));
                }
            }

            foreach ($type->all as $field) {
                if ($field->isNew() && !$field->removed) {
                    array_push($operations, ...$this->added($type, $field));
                }
            }
        }

        return $operations;
    }

    /**
     * What changed what $field holds, in order, under its final name,
     * starting from $holds. One added back holding what it held just before
     * is nothing; holding something else, it's reset, or removed and added
     * again when it has an initializer.
     * @return list<SchemaOperation>
     */
    private function changesOf(TypeTrack $type, FieldTrack $field, FieldDescriptor $holds): array {
        $operations = [];
        foreach ($field->changes as $change) {
            if ($change instanceof MakeRequired) {
                $operations[] = new MakeRequired($type->name, $field->name);
                $holds = $holds->withNullability(Nullability::NotNullable);
                continue;
            }

            $descriptor = $change->field->renamed($field->name);
            if ($change instanceof AddField) {
                if ($change->initializer !== null) {
                    $operations[] = new RemoveField($type->name, $field->name);
                    $operations[] = new AddField($type->name, $descriptor, $change->default, $change->initializer);
                    $this->pinnedByChange[spl_object_id($field)] = $change->default;
                } elseif ($descriptor != $holds) {
                    $operations[] = new RetypeField($type->name, $descriptor, null, $change->default);
                    $this->pinnedByChange[spl_object_id($field)] = $change->default;
                }
            } else {
                $operations[] = new RetypeField($type->name, $descriptor, $change->converter, $change->default);
                $this->pinnedByChange[spl_object_id($field)] = $change->default;
            }

            $holds = $descriptor;
        }

        return $operations;
    }

    /**
     * A new field, as it ends up: one "add a field". One whose values a
     * converter changed after an initializer gave them is added as it was,
     * then changed, then given its last default.
     * @return list<SchemaOperation>
     */
    private function added(TypeTrack $type, FieldTrack $field): array {
        $converts = array_filter($field->changes, static fn (SchemaOperation $change): bool => $change instanceof RetypeField && $change->converter !== null);
        if ($field->initializer === null && $converts === []) {
            $default = $field->descriptor->kind() === FieldKind::Embed ? null : $field->default;

            return [new AddField($type->name, $field->descriptor, $default, $field->initializer)];
        }

        $added = $field->addedBy;
        $operations = [new AddField($type->name, $added->field->renamed($field->name), $added->default, $added->initializer)];
        array_push($operations, ...$this->changesOf($type, $field, $added->field->renamed($field->name)));
        if ($field->default != $added->default && $field->default !== null) {
            $operations[] = new SetDefault($type->name, $field->name, $field->default);
        }

        return $operations;
    }

    /** @return list<SchemaOperation> The last default change at each path left, under the final names. */
    private function defaultChanges(): array {
        $operations = [];
        foreach ($this->defaults as $default) {
            if ($default->isGone() || $this->isAsStored($default) || $this->isPinnedByChange($default)) {
                continue;
            }

            $path = $default->fieldName() . $default->rest;
            $operations[] = $default->value === null
                ? new InheritDefault($default->type->name, $path)
                : new SetDefault($default->type->name, $path, $default->value);
        }

        return $operations;
    }

    /** Whether the field change emitted last for $default's field pins the same default at its own path. */
    private function isPinnedByChange(DefaultTrack $default): bool {
        $field = $default->field;
        if (!$field instanceof FieldTrack || $default->rest !== '' || !array_key_exists(spl_object_id($field), $this->pinnedByChange)) {
            return false;
        }

        return $this->pinnedByChange[spl_object_id($field)] == $default->value;
    }

    /**
     * Whether $default leaves the type as stored: pinning what it already
     * pins at a stored field nothing changed, or inheriting where it pins
     * nothing.
     */
    private function isAsStored(DefaultTrack $default): bool {
        $field = $default->field;
        if ($default->type->isNew() || ($field instanceof FieldTrack && !$field->holdsWhatItHeld())) {
            return false;
        }

        $own = $this->ownDefaults[$default->type->original] ?? [];
        $path = FieldPath::fromString(($field instanceof FieldTrack ? $field->original : $field) . $default->rest);
        if ($default->value !== null) {
            return array_key_exists((string) $path, $own) && $own[(string) $path] === $default->value->value;
        }

        foreach (array_keys($own) as $set) {
            if (FieldPath::fromString((string) $set)->startsWith($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * $renames ordered so each takes a name free by then: one whose new name
     * another still holds waits for it. Names swapped in a cycle can't be
     * ordered: the save is refused.
     * @param list<array{string, string}> $renames Old and new name each.
     * @param array<string, true> $taken The names held before any rename.
     * @return list<array{string, string}>
     */
    private static function ordered(array $renames, array $taken, string $what): array {
        $ordered = [];
        while ($renames !== []) {
            $progress = false;
            foreach ($renames as $index => [$old, $new]) {
                if (isset($taken[$new])) {
                    continue;
                }

                $ordered[] = [$old, $new];
                unset($taken[$old], $renames[$index]);
                $taken[$new] = true;
                $progress = true;
            }

            if (!$progress) {
                throw new InvalidOperation(sprintf('%s swap names (%s): save one of the renames first.', $what, implode(', ', array_map(static fn (array $rename): string => $rename[0] . ' → ' . $rename[1], $renames))));
            }
        }

        return $ordered;
    }
}

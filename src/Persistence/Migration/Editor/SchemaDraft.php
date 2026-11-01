<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\FieldRuleUsage;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * What an editor save makes of the editor-created types, built operation by
 * operation: every editor-created type's new definition, the fixups moving
 * stored content there, and the default changes applied after. Native types
 * are only read: an operation changing one is refused.
 *
 * A type the save creates holds no content, so it needs no fixup: its final
 * definition joins the plan's starting state, as if it had always been
 * there. Changing it changes only its definition.
 */
final class SchemaDraft
{
    /** @var array<string, EntitySchema> Every editor-created type, keyed by identifier. */
    private array $editor;

    /** @var array<string, true> The types this save creates, keyed by identifier. */
    private array $created = [];

    /** @var list<Fixup> */
    private array $fixups = [];

    /** @var list<DefaultChange> */
    private array $defaults = [];

    /** @var array<string, true> Every identifier a type had when the save started. */
    private readonly array $original;

    /**
     * @param array<string, EntitySchema> $native Every native type, keyed by identifier.
     * @param array<string, EntitySchema> $editor Every editor-created type, keyed by identifier.
     */
    public function __construct(
        private readonly array $native,
        array $editor,
    ) {
        $this->editor = $editor;
        $this->original = array_fill_keys(array_map('strval', [...array_keys($native), ...array_keys($editor)]), true);
    }

    /** Whether a type, native or editor-created, is named $identifier now. */
    public function has(string $identifier): bool {
        return isset($this->native[$identifier]) || isset($this->editor[$identifier]);
    }

    /** Type $identifier now, native or editor-created. */
    public function schema(string $identifier): EntitySchema {
        return $this->native[$identifier] ?? $this->editor[$identifier] ?? throw new InvalidOperation(sprintf('There is no type "%s".', $identifier));
    }

    /** Editor-created type $identifier now: what an operation may change. Refuses a native one. */
    public function editorType(string $identifier): EntitySchema {
        if (isset($this->native[$identifier])) {
            throw new InvalidOperation(sprintf('"%s" is a native type: the editor changes editor-created types only.', $identifier));
        }

        return $this->schema($identifier);
    }

    /** Whether this save creates type $identifier. */
    public function isCreated(string $identifier): bool {
        return isset($this->created[$identifier]);
    }

    /** Editor-created type $identifier's own field $name. */
    public function ownField(string $identifier, FieldName $name): FieldDescriptor {
        $schema = $this->editorType($identifier);
        if (!$schema->fields->has($name)) {
            throw new InvalidOperation(sprintf('Type "%s" has no field "%s" of its own.', $identifier, $name));
        }

        return $schema->fields->get($name);
    }

    /** Throws when type $identifier or one of its ancestors already has a field $name. */
    public function assertNoField(string $identifier, FieldName $name): void {
        for ($level = $identifier; $level !== null; $level = $this->schema($level)->parent) {
            if ($this->schema($level)->fields->has($name)) {
                throw new InvalidOperation(sprintf('Type "%s" already has a field "%s"%s.', $identifier, $name, $level === $identifier ? '' : sprintf(', from "%s"', $level)));
            }
        }
    }

    /** Whether type $identifier is $ancestor or extends it, now. */
    public function isA(string $identifier, string $ancestor): bool {
        for ($level = $identifier; $level !== null; $level = $this->schema($level)->parent) {
            if ($level === $ancestor) {
                return true;
            }
        }

        return false;
    }

    /** Throws unless type $identifier or one of its ancestors has a field $name. */
    public function assertHasField(string $identifier, FieldName $name): void {
        $this->fieldOf($identifier, $name);
    }

    /** Field $name of type $identifier: its own, or an ancestor's. */
    public function fieldOf(string $identifier, FieldName $name): FieldDescriptor {
        for ($level = $identifier; $level !== null; $level = $this->schema($level)->parent) {
            if ($this->schema($level)->fields->has($name)) {
                return $this->schema($level)->fields->get($name);
            }
        }

        throw new InvalidOperation(sprintf('Type "%s" has no field "%s".', $identifier, $name));
    }

    /**
     * Throws unless $identifier is a well-formed "namespace:key" no type has
     * now. A type this save creates can't take a name a type had when the save
     * started: save the rename or the delete first.
     */
    public function assertFreeName(string $identifier, bool $creating): void {
        try {
            $parsed = Identifier::fromString($identifier);
        } catch (InvalidArgumentException $invalid) {
            throw new InvalidOperation($invalid->getMessage(), 0, $invalid);
        }

        if ($parsed->namespace === '' || $parsed->key === '') {
            throw new InvalidOperation(sprintf('"%s" is not a "namespace:key" identifier.', $identifier));
        }

        if ($this->has($identifier)) {
            throw new InvalidOperation(sprintf('A type "%s" already exists.', $identifier));
        }

        if ($creating && isset($this->original[$identifier])) {
            throw new InvalidOperation(sprintf('"%s" named another type when this save started: save its rename or delete first.', $identifier));
        }
    }

    /**
     * Throws unless every editor-created type's rules still fit it: each field
     * rule its field, each cross-field rule the type's own fields. Run after
     * every operation, so one breaking a rule, say a rename of a field a
     * cross-field rule names, is refused, and the save sets the rule anew.
     */
    public function assertRulesFit(): void {
        foreach ($this->editor as $identifier => $schema) {
            try {
                foreach ($schema->fieldValidators as $name => $rules) {
                    foreach ($rules as $rule) {
                        FieldRuleUsage::check($rule, $schema->fields->get($name));
                    }
                }

                foreach ($schema->prototypeValidators as $rule) {
                    $rule->checkUsage($schema->fields);
                }
            } catch (InvalidArgumentException $broken) {
                throw new InvalidOperation(sprintf('It breaks a rule of "%s": %s Set its rules anew in this save.', $identifier, $broken->getMessage()), 0, $broken);
            }
        }
    }

    /** $schema added as a type this save creates. */
    public function create(EntitySchema $schema): void {
        $this->editor[$schema->identifier] = $schema;
        $this->created[$schema->identifier] = true;
    }

    /** $schema replacing the editor-created type with its identifier. */
    public function replace(EntitySchema $schema): void {
        $this->editorType($schema->identifier);
        $this->editor[$schema->identifier] = $schema;
    }

    /**
     * Editor-created type $old renamed to $renamed's identifier: every
     * editor-created parent and field naming it follows. A native type never
     * names an editor-created one.
     */
    public function rename(string $old, EntitySchema $renamed): void {
        $this->editorType($old);
        unset($this->editor[$old]);
        $this->editor[$renamed->identifier] = $renamed;
        if (isset($this->created[$old])) {
            unset($this->created[$old]);
            $this->created[$renamed->identifier] = true;
        }

        foreach ($this->editor as $identifier => $schema) {
            $this->editor[$identifier] = $schema
                ->withParent($schema->parent === $old ? $renamed->identifier : $schema->parent)
                ->withFields($schema->fields->retargeted($old, $renamed->identifier));
        }

        // A default change on the type follows it.
        $this->defaults = array_map(static fn (DefaultChange $change): DefaultChange => $change->type === $old ? $change->onType($renamed->identifier) : $change, $this->defaults);
        $this->pinnedClassRenamed($old, $renamed->identifier);
    }

    /**
     * Editor-created type $old replaced by $with, as ReplaceType replaces it:
     * $old goes, with the default changes made on it; a direct subtype
     * replacing it takes its place in the chain; every other subtype moves
     * under $with, and every field naming $old names $with.
     */
    public function replaceType(string $old, string $with): void {
        $replaced = $this->editorType($old);
        if (isset($this->editor[$with]) && $this->editor[$with]->parent === $old) {
            $this->editor[$with] = $this->editor[$with]->withParent($replaced->parent);
        }

        $this->remove($old);
        foreach ($this->editor as $identifier => $schema) {
            $this->editor[$identifier] = $schema
                ->withParent($schema->parent === $old ? $with : $schema->parent)
                ->withFields($schema->fields->retargeted($old, $with));
        }

        $this->pinnedClassRenamed($old, $with);
    }

    /**
     * Every pending pin naming $old as the class an owned slot holds, at any
     * depth, names $new now.
     */
    private function pinnedClassRenamed(string $old, string $new): void {
        $this->defaults = array_map(function (DefaultChange $change) use ($old, $new): DefaultChange {
            if (!$change->pins() || $change->value->value !== $old) {
                return $change;
            }

            $segments = $this->walk($change->type, $change->path);

            return $segments[array_key_last($segments)][3]?->kind() === FieldKind::OwningReference ? $change->pinning(new DefaultValue($new)) : $change;
        }, $this->defaults);
    }

    /** Editor-created type $identifier removed, with the default changes made on it. */
    public function remove(string $identifier): void {
        $this->editorType($identifier);
        unset($this->editor[$identifier], $this->created[$identifier]);
        $this->defaults = array_values(array_filter($this->defaults, static fn (DefaultChange $change): bool => $change->type !== $identifier));
    }

    /** Every field type $identifier holds now, its ancestors' included, root level first. */
    public function fieldsOf(string $identifier): FieldDescriptorCollection {
        $chain = [];
        for ($level = $identifier; $level !== null; $level = $this->schema($level)->parent) {
            array_unshift($chain, $this->schema($level));
        }

        $fields = FieldDescriptorCollection::empty();
        foreach ($chain as $schema) {
            $fields = $fields->merge($schema->fields);
        }

        return $fields;
    }

    /**
     * Field $old of type $identifier renamed $new: every default change made
     * so far through it starts at, or goes through, $new now, at any depth:
     * on the type or a subtype, or on any type reaching it through an Embed,
     * an owned object or a Collection's item. Default changes apply once the
     * content moved, under the final names.
     */
    public function fieldRenamed(string $identifier, FieldName $old, FieldName $new): void {
        $renames = fn (string $owner, FieldName $name): FieldName => (string) $name === (string) $old && $this->declares($owner, $identifier, $old) ? $new : $name;
        $this->defaults = array_map(
            fn (DefaultChange $change): DefaultChange => $change->onPath(self::pathOf($this->walk($change->type, $change->path, $renames))),
            $this->defaults,
        );
    }

    /**
     * Drops every default change made so far through field $name of type
     * $identifier, at any depth, but those reaching it on $kept or its
     * subtypes: the field is removed, moved down to $kept, or retyped, so
     * what was pinned no longer fits it.
     */
    public function dropDefaultChanges(string $identifier, FieldName $name, ?string $kept = null): void {
        $this->defaults = array_values(array_filter($this->defaults, function (DefaultChange $change) use ($identifier, $name, $kept): bool {
            foreach ($this->walk($change->type, $change->path) as [$segment, , $owner]) {
                if ($owner !== null && (string) $segment === (string) $name && $this->reaches($owner, $identifier, $name) && !($kept !== null && $this->isA($owner, $kept))) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Whether field $name, found on $owner, is type $identifier's: $owner is
     * $identifier or a subtype, or an ancestor holding no field of that name
     * itself, reached where it holds an $identifier.
     */
    private function reaches(string $owner, string $identifier, FieldName $name): bool {
        if (!$this->relates($owner, $identifier)) {
            return false;
        }

        return $this->isA($owner, $identifier) || $this->tryFieldOf($owner, $name) === null;
    }

    /**
     * Whether field $name of $owner is the one type $identifier declares: $owner
     * is $identifier, a subtype or an ancestor of it, and holds no other
     * field of that name. Called once $identifier's field changed, so a field
     * $owner still finds by $name is one of its own.
     */
    private function declares(string $owner, string $identifier, FieldName $name): bool {
        if (!$this->relates($owner, $identifier)) {
            return false;
        }

        try {
            $this->fieldOf($owner, $name);

            return false;
        } catch (InvalidOperation) {
            return true;
        }
    }

    /** Whether $a and $b are one chain: either is the other or extends it. False when either is gone. */
    private function relates(string $a, string $b): bool {
        try {
            return $this->isA($a, $b) || $this->isA($b, $a);
        } catch (InvalidOperation) {
            return false;
        }
    }

    /**
     * Each segment of $path from type $type: the segment, the join before it,
     * the type it's a field of (null for an index, or past what doesn't
     * resolve), and the field it resolves to. $named gives the name a segment
     * resolves by, on the type it's a field of: what a rename makes of it.
     * @param (callable(string, FieldName): FieldName)|null $named
     * @return list<array{FieldName|int, ?string, ?string, ?FieldDescriptor}>
     */
    private function walk(string $type, FieldPath $path, ?callable $named = null): array {
        $segments = [];
        $owner = $type;
        $item = null;
        $join = null;
        for ($rest = $path; $rest !== null; $rest = $rest->tail()) {
            $head = $rest->head();
            if (is_int($head)) {
                // A Collection's index: what follows is a field of its item.
                $segments[] = [$head, $join, null, $item];
                $owner = $item !== null && in_array($item->kind(), [FieldKind::Embed, FieldKind::OwningReference], true) ? $item->target() : null;
                $item = null;
            } else {
                $name = $owner !== null && $named !== null ? $named($owner, $head) : $head;
                $field = $owner === null ? null : $this->tryFieldOf($owner, $name);
                $segments[] = [$name, $join, $owner, $field];
                $owner = in_array($field?->kind(), [FieldKind::Embed, FieldKind::OwningReference], true) ? $field->target() : null;
                $item = $field?->kind() === FieldKind::Collection ? $field->itemDescriptor() : null;
            }

            $join = $rest->headJoin();
        }

        return $segments;
    }

    /**
     * The path $segments spell, as walk() gives them.
     * @param list<array{FieldName|int, ?string, ?string, ?FieldDescriptor}> $segments
     */
    private static function pathOf(array $segments): FieldPath {
        $path = '';
        foreach ($segments as [$segment, $join]) {
            $path .= ($join ?? '') . $segment;
        }

        return FieldPath::fromString($path);
    }

    /** Field $name of $owner, or null when $owner or the field is gone. */
    private function tryFieldOf(string $owner, FieldName $name): ?FieldDescriptor {
        try {
            return $this->fieldOf($owner, $name);
        } catch (InvalidOperation) {
            return null;
        }
    }

    /**
     * Adds $fixup, moving stored content: never for a type this save creates.
     * Called once its operation changed the definitions: it heads for them as
     * they are now.
     */
    public function fixup(Fixup $fixup): void {
        $this->fixups[] = new FixupAtStep($fixup, $this->editor);
    }

    /** Adds $change, applied once the content moved. */
    public function changeDefault(DefaultChange $change): void {
        $this->defaults[] = $change;
    }

    /**
     * Every editor-created type's new definition: what the fixups take new
     * shapes from.
     * @return array<string, EntitySchema> Keyed by identifier.
     */
    public function targets(): array {
        return $this->editor;
    }

    /**
     * The types this save creates, in their final definition.
     * @return array<string, EntitySchema> Keyed by identifier.
     */
    public function created(): array {
        return array_intersect_key($this->editor, $this->created);
    }

    /**
     * The fixups, in the order the operations gave them.
     * @return list<Fixup>
     */
    public function fixups(): array {
        return $this->fixups;
    }

    /**
     * The default changes, in the order the operations gave them.
     * @return list<DefaultChange>
     */
    public function defaultChanges(): array {
        return $this->defaults;
    }
}

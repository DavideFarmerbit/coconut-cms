<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

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
        for ($level = $identifier; $level !== null; $level = $this->schema($level)->parent) {
            if ($this->schema($level)->fields->has($name)) {
                return;
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

        $this->defaults = array_map(static fn (DefaultChange $change): DefaultChange => $change->type === $old ? $change->onType($renamed->identifier) : $change, $this->defaults);
    }

    /** Editor-created type $identifier removed, with the default changes made on it. */
    public function remove(string $identifier): void {
        $this->editorType($identifier);
        unset($this->editor[$identifier], $this->created[$identifier]);
        $this->defaults = array_values(array_filter($this->defaults, static fn (DefaultChange $change): bool => $change->type !== $identifier));
    }

    /**
     * Field $old of type $identifier renamed $new: every default change made
     * so far through it, on the type or a subtype, starts at $new now. Default
     * changes apply once the content moved, under the final names.
     */
    public function fieldRenamed(string $identifier, FieldName $old, FieldName $new): void {
        $this->defaults = array_map(
            fn (DefaultChange $change): DefaultChange => $change->firstField() === (string) $old && $this->isA($change->type, $identifier) ? $change->startingAt((string) $new) : $change,
            $this->defaults,
        );
    }

    /**
     * Drops every default change made so far through field $name, on type
     * $identifier or a subtype, but those on $kept or its subtypes: the field
     * is removed, moved down to $kept, or retyped, so what was pinned no
     * longer fits it.
     */
    public function dropDefaultChanges(string $identifier, FieldName $name, ?string $kept = null): void {
        $this->defaults = array_values(array_filter(
            $this->defaults,
            fn (DefaultChange $change): bool => $change->firstField() !== (string) $name
                || !$this->isA($change->type, $identifier)
                || ($kept !== null && $this->isA($change->type, $kept)),
        ));
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

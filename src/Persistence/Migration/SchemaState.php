<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use Closure;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * The stored schemas as the fixups so far have transformed them, next to the
 * target schemas they head for: the code's native schemas, and in an editor
 * save the editor-created schemas it saves. Immutable: each fixup returns a
 * new state. Asking for something that doesn't exist throws InvalidFixup:
 * what a fixup names must exist when it runs.
 */
final readonly class SchemaState
{
    /**
     * @param array<string, EntitySchema> $schemas Keyed by identifier, every
     *        source included.
     * @param array<string, SchemaSource> $sources Keyed by identifier.
     * @param array<string, EntitySchema> $target The schemas the fixups head
     *        for, keyed by identifier.
     * @param array<string, SchemaSource> $targetSources Keyed by identifier.
     */
    private function __construct(
        private array $schemas,
        private array $sources,
        private array $target,
        private array $targetSources,
    ) {
    }

    /**
     * @param list<StoredSchema> $stored Every stored schema, of every source.
     * @param array<string, EntitySchema> $code The native schemas compiled
     *        from code, keyed by identifier.
     * @param array<string, EntitySchema> $editor The editor-created schemas an
     *        editor save heads for, keyed by identifier; none in a native
     *        migration.
     */
    public static function of(array $stored, array $code, array $editor = []): self {
        $schemas = [];
        $sources = [];
        foreach ($stored as $each) {
            $schemas[$each->schema->identifier] = $each->schema;
            $sources[$each->schema->identifier] = $each->source;
        }

        return new self(
            $schemas,
            $sources,
            [...$code, ...$editor],
            [...array_map(static fn (): SchemaSource => SchemaSource::Native, $code), ...array_map(static fn (): SchemaSource => SchemaSource::Editor, $editor)],
        );
    }

    public function has(string $identifier): bool {
        return isset($this->schemas[$identifier]);
    }

    public function get(string $identifier): EntitySchema {
        return $this->schemas[$identifier] ?? throw new InvalidFixup(sprintf('there is no type "%s" at this point.', $identifier));
    }

    /** Throws when $identifier already exists: a type can't be added or renamed onto another. */
    public function assertAbsent(string $identifier): void {
        if ($this->has($identifier)) {
            throw new InvalidFixup(sprintf('type "%s" already exists at this point.', $identifier));
        }
    }

    /** Field $name of type $identifier, which must exist at this point. */
    public function field(string $identifier, FieldName $name): FieldDescriptor {
        $schema = $this->get($identifier);
        if (!$schema->fields->has($name)) {
            throw new InvalidFixup(sprintf('type "%s" has no field "%s" at this point.', $identifier, $name));
        }

        return $schema->fields->get($name);
    }

    /**
     * Throws unless $path leads somewhere in type $identifier's aggregate at
     * this point: each name a field where it stands, ":" only into an Embed,
     * "." only into an owned object or after a Collection's index. Past an
     * owned slot, a field of any type extending its target counts.
     */
    public function assertPath(string $identifier, FieldPath $path): void {
        if (!$this->leadsSomewhere([$identifier], $path)) {
            throw new InvalidFixup(sprintf('type "%s" has no path "%s" at this point.', $identifier, $path));
        }
    }

    /** @param list<string> $types The types $path may start in. */
    private function leadsSomewhere(array $types, FieldPath $path): bool {
        $field = null;
        foreach ($types as $type) {
            $fields = $this->hierarchyFieldsOf($type);
            if (!is_int($path->head()) && $fields->has($path->head())) {
                $field = $fields->get($path->head());
                break;
            }
        }

        $rest = $path->tail();
        if ($field === null || $rest === null) {
            return $field !== null;
        }

        $join = $path->headJoin();

        return match ($field->kind()) {
            FieldKind::Embed => $join === FieldPath::EMBED && $this->leadsSomewhere([$field->target()], $rest),
            FieldKind::OwningReference => $join === FieldPath::TRAVERSE && $this->leadsSomewhere($this->descendantsOf($field->target()), $rest),
            FieldKind::Collection => $join === FieldPath::TRAVERSE && is_int($rest->head()) && $this->leadsIntoItem($field->itemDescriptor(), $rest),
            default => false,
        };
    }

    /** Whether $path, starting at an item's index, leads somewhere in the item. */
    private function leadsIntoItem(FieldDescriptor $item, FieldPath $path): bool {
        $rest = $path->tail();
        if ($rest === null) {
            return true;
        }

        if ($path->headJoin() !== FieldPath::TRAVERSE) {
            return false;
        }

        return match ($item->kind()) {
            FieldKind::Embed => $this->leadsSomewhere([$item->target()], $rest),
            FieldKind::OwningReference => $this->leadsSomewhere($this->descendantsOf($item->target()), $rest),
            default => false,
        };
    }

    /**
     * $identifier and every type extending it, at this point.
     * @return list<string>
     */
    private function descendantsOf(string $identifier): array {
        return array_values(array_filter(array_keys($this->schemas), fn (string $each): bool => $this->isA($each, $identifier)));
    }

    /** Throws when type $identifier already has a field $name. */
    public function assertNoField(string $identifier, FieldName $name): void {
        if ($this->get($identifier)->fields->has($name)) {
            throw new InvalidFixup(sprintf('type "%s" already has a field "%s" at this point.', $identifier, $name));
        }
    }

    /** $identifier's target schema: what a fixup takes a new shape from. */
    public function target(string $identifier): EntitySchema {
        return $this->target[$identifier] ?? throw new InvalidFixup(sprintf('the target has no type "%s".', $identifier));
    }

    /** Field $name of type $identifier in its target schema. */
    public function targetField(string $identifier, FieldName $name): FieldDescriptor {
        $schema = $this->target($identifier);
        if (!$schema->fields->has($name)) {
            throw new InvalidFixup(sprintf('type "%s" has no field "%s" in the target.', $identifier, $name));
        }

        return $schema->fields->get($name);
    }

    /**
     * $identifier and its ancestors at this point, root first.
     * @return list<string>
     */
    public function chainOf(string $identifier): array {
        $chain = [];
        for ($current = $identifier; $current !== null; $current = $this->get($current)->parent) {
            $chain[] = $current;
        }

        return array_reverse($chain);
    }

    /** Whether $identifier is $ancestor or descends from it, at this point. */
    public function isA(string $identifier, string $ancestor): bool {
        return in_array($ancestor, $this->chainOf($identifier), true);
    }

    /** Every field $identifier holds at this point, its ancestors' included, root level first. */
    public function hierarchyFieldsOf(string $identifier): FieldDescriptorCollection {
        $fields = FieldDescriptorCollection::empty();
        foreach ($this->chainOf($identifier) as $level) {
            $fields = $fields->merge($this->get($level)->fields);
        }

        return $fields;
    }

    /**
     * $identifier's ancestors in the target, nearest first.
     * @return list<string>
     */
    public function targetAncestorsOf(string $identifier): array {
        $ancestors = [];
        for ($parent = $this->target($identifier)->parent; $parent !== null; $parent = $this->target($parent)->parent) {
            $ancestors[] = $parent;
        }

        return $ancestors;
    }

    /** $schema replacing the type with its identifier, keeping its source. */
    public function with(EntitySchema $schema): self {
        $this->get($schema->identifier);

        return new self([...$this->schemas, $schema->identifier => $schema], $this->sources, $this->target, $this->targetSources);
    }

    /** Type $identifier added from the target, with the target's source. */
    public function withTargetType(string $identifier): self {
        $this->assertAbsent($identifier);

        return new self(
            [...$this->schemas, $identifier => $this->target($identifier)],
            [...$this->sources, $identifier => $this->targetSources[$identifier]],
            $this->target,
            $this->targetSources,
        );
    }

    public function without(string $identifier): self {
        $this->get($identifier);
        $schemas = $this->schemas;
        $sources = $this->sources;
        unset($schemas[$identifier], $sources[$identifier]);

        return new self($schemas, $sources, $this->target, $this->targetSources);
    }

    /** Type $old as $renamed, under its new identifier, keeping its source. */
    public function renamed(string $old, EntitySchema $renamed): self {
        $source = $this->sources[$old] ?? null;
        $state = $this->without($old);
        $state->assertAbsent($renamed->identifier);

        return new self(
            [...$state->schemas, $renamed->identifier => $renamed],
            [...$state->sources, $renamed->identifier => $source],
            $this->target,
            $this->targetSources,
        );
    }

    /** @param Closure(EntitySchema): EntitySchema $change Applied to every schema, of every source. */
    public function map(Closure $change): self {
        return new self(array_map($change, $this->schemas), $this->sources, $this->target, $this->targetSources);
    }

    /**
     * Every schema at this point, of every source.
     * @return array<string, EntitySchema> Keyed by identifier.
     */
    public function schemas(): array {
        return $this->schemas;
    }

    /** The source of type $identifier: native or editor-created. */
    public function sourceOf(string $identifier): SchemaSource {
        $this->get($identifier);

        return $this->sources[$identifier];
    }

    /**
     * The native schemas: what the code is compared with.
     * @return array<string, EntitySchema> Keyed by identifier.
     */
    public function native(): array {
        return array_filter($this->schemas, fn (EntitySchema $schema): bool => $this->sources[$schema->identifier] === SchemaSource::Native);
    }

    /**
     * The editor-created schemas at this point.
     * @return array<string, EntitySchema> Keyed by identifier.
     */
    public function editor(): array {
        return array_filter($this->schemas, fn (EntitySchema $schema): bool => $this->sources[$schema->identifier] === SchemaSource::Editor);
    }

    /**
     * The target schemas, unchanged by any fixup.
     * @return array<string, EntitySchema> Keyed by identifier.
     */
    public function targetSchemas(): array {
        return $this->target;
    }
}

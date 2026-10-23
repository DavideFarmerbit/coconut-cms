<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use Closure;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;

/**
 * The stored schemas as the fixups so far have transformed them, next to the
 * code's schemas they head for. Immutable: each fixup returns a new state.
 * Asking for something that doesn't exist throws InvalidFixup: what a fixup
 * names must exist when it runs.
 */
final readonly class SchemaState
{
    /**
     * @param array<string, EntitySchema> $schemas Keyed by identifier, every
     *        source included.
     * @param array<string, SchemaSource> $sources Keyed by identifier.
     * @param array<string, EntitySchema> $code The native schemas compiled
     *        from code, keyed by identifier.
     */
    private function __construct(
        private array $schemas,
        private array $sources,
        private array $code,
    ) {
    }

    /**
     * @param list<StoredSchema> $stored Every stored schema, of every source.
     * @param array<string, EntitySchema> $code Keyed by identifier.
     */
    public static function of(array $stored, array $code): self {
        $schemas = [];
        $sources = [];
        foreach ($stored as $each) {
            $schemas[$each->schema->identifier] = $each->schema;
            $sources[$each->schema->identifier] = $each->source;
        }

        return new self($schemas, $sources, $code);
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

    /** Throws when type $identifier already has a field $name. */
    public function assertNoField(string $identifier, FieldName $name): void {
        if ($this->get($identifier)->fields->has($name)) {
            throw new InvalidFixup(sprintf('type "%s" already has a field "%s" at this point.', $identifier, $name));
        }
    }

    /** $identifier's schema in code: what a fixup takes a new shape from. */
    public function code(string $identifier): EntitySchema {
        return $this->code[$identifier] ?? throw new InvalidFixup(sprintf('the code has no type "%s".', $identifier));
    }

    /** Field $name of type $identifier in code. */
    public function codeField(string $identifier, FieldName $name): FieldDescriptor {
        $schema = $this->code($identifier);
        if (!$schema->fields->has($name)) {
            throw new InvalidFixup(sprintf('type "%s" has no field "%s" in the code.', $identifier, $name));
        }

        return $schema->fields->get($name);
    }

    /**
     * $identifier's ancestors in code, nearest first.
     * @return list<string>
     */
    public function codeAncestorsOf(string $identifier): array {
        $ancestors = [];
        for ($parent = $this->code($identifier)->parent; $parent !== null; $parent = $this->code($parent)->parent) {
            $ancestors[] = $parent;
        }

        return $ancestors;
    }

    /** $schema replacing the type with its identifier, keeping its source. */
    public function with(EntitySchema $schema): self {
        $this->get($schema->identifier);

        return new self([...$this->schemas, $schema->identifier => $schema], $this->sources, $this->code);
    }

    /** Type $identifier added from the code: a native type. */
    public function withCodeType(string $identifier): self {
        $this->assertAbsent($identifier);

        return new self(
            [...$this->schemas, $identifier => $this->code($identifier)],
            [...$this->sources, $identifier => SchemaSource::Native],
            $this->code,
        );
    }

    public function without(string $identifier): self {
        $this->get($identifier);
        $schemas = $this->schemas;
        $sources = $this->sources;
        unset($schemas[$identifier], $sources[$identifier]);

        return new self($schemas, $sources, $this->code);
    }

    /** Type $old as $renamed, under its new identifier, keeping its source. */
    public function renamed(string $old, EntitySchema $renamed): self {
        $source = $this->sources[$old] ?? null;
        $state = $this->without($old);
        $state->assertAbsent($renamed->identifier);

        return new self(
            [...$state->schemas, $renamed->identifier => $renamed],
            [...$state->sources, $renamed->identifier => $source],
            $this->code,
        );
    }

    /** @param Closure(EntitySchema): EntitySchema $change Applied to every schema, of every source. */
    public function map(Closure $change): self {
        return new self(array_map($change, $this->schemas), $this->sources, $this->code);
    }

    /**
     * The native schemas: what the code is compared with.
     * @return array<string, EntitySchema> Keyed by identifier.
     */
    public function native(): array {
        return array_filter($this->schemas, fn (EntitySchema $schema): bool => $this->sources[$schema->identifier] === SchemaSource::Native);
    }

    /**
     * The code's schemas, unchanged by any fixup.
     * @return array<string, EntitySchema> Keyed by identifier.
     */
    public function codeSchemas(): array {
        return $this->code;
    }
}

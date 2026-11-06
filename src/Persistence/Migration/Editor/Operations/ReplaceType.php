<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * An editor-created type replaced by $with, as ReplaceType replaces it: every
 * entry becomes a $with, keeping its id, so References and owned slots stay
 * valid. $with is any type but a deeper descendant: an editor-created one,
 * or a native one, content then moving into the code's type. Values follow
 * field names: a field both have, the same, keeps its value; one only the
 * old type has goes; one only $with has follows its default, or an
 * initializer; one both have, differently, takes a converter or a reset.
 * Subtypes move under $with, which must allow them, and every field naming
 * the old type names $with.
 */
final readonly class ReplaceType implements SchemaOperation
{
    /**
     * @param array<string, FieldInitializer> $initializers By field name: a field only $with has.
     * @param array<string, FieldConverter> $converters By field name: a field both have, differently.
     * @param list<string> $resets The fields both have, differently, whose values go.
     */
    public function __construct(
        public string $type,
        public string $with,
        public array $initializers = [],
        public array $converters = [],
        public array $resets = [],
    ) {
    }

    public function applyTo(SchemaDraft $draft): void {
        $draft->editorType($this->type);
        $draft->schema($this->with);
        if ($this->type === $this->with) {
            throw new InvalidOperation(sprintf('Type "%s" can\'t replace itself.', $this->type));
        }

        if ($draft->isA($this->with, $this->type) && $draft->schema($this->with)->parent !== $this->type) {
            throw new InvalidOperation(sprintf('"%s" descends from "%s" through another of its subtypes: only a direct subtype can take its place.', $this->with, $this->type));
        }

        $created = $draft->isCreated($this->type);
        if ($created && ($this->initializers !== [] || $this->converters !== [])) {
            throw new InvalidOperation(sprintf('Type "%s" is new in this save: it has no entries to fill or convert.', $this->type));
        }

        $old = $draft->fieldsOf($this->type);
        $subtypes = array_keys(array_filter($draft->targets(), fn ($schema): bool => $schema->parent === $this->type && $schema->identifier !== $this->with));
        if ($subtypes !== [] && !$draft->schema($this->with)->editorExtensible) {
            throw new InvalidOperation(sprintf('"%s" doesn\'t allow editor-created subtypes, and %s would move under it.', $this->with, implode(', ', $subtypes)));
        }

        $draft->replaceType($this->type, $this->with);
        $this->assertDeclared($old, $draft->fieldsOf($this->with));
        if (!$created) {
            $draft->fixup(new Fixup\ReplaceType($this->type, $this->with, $this->initializers, $this->converters, $this->resets));
        }
    }

    /**
     * Throws unless every field both types have differently is converted or
     * reset, every name given is one that fits, and each converter and
     * initializer fits its field.
     */
    private function assertDeclared(FieldDescriptorCollection $old, FieldDescriptorCollection $new): void {
        $differing = [];
        $gained = [];
        foreach ($new as $name => $field) {
            if (!$old->has($name)) {
                $gained[] = (string) $name;
            } elseif (!$old->get($name)->sameAs($field)) {
                $differing[] = (string) $name;
            }
        }

        $undeclared = array_diff($differing, array_keys($this->converters), $this->resets);
        if ($undeclared !== []) {
            throw new InvalidOperation(sprintf('%s differ between "%s" and "%s": give each a converter, or reset it.', implode(', ', array_map(static fn (string $name): string => '"' . $name . '"', $undeclared)), $this->type, $this->with));
        }

        foreach ([...array_keys($this->converters), ...$this->resets] as $name) {
            if (!in_array($name, $differing, true)) {
                throw new InvalidOperation(sprintf('"%s" isn\'t a field "%s" and "%s" both have differently: nothing to convert or reset.', $name, $this->type, $this->with));
            }
        }

        foreach (array_keys($this->initializers) as $name) {
            if (!in_array($name, $gained, true)) {
                throw new InvalidOperation(sprintf('"%s" isn\'t a field only "%s" has: nothing to initialize.', $name, $this->with));
            }
        }

        foreach ($this->converters as $name => $converter) {
            $from = $old->get(FieldName::of($name));
            $to = $new->get(FieldName::of($name));
            if (!$converter::from()->matches($from) || !$converter::to()->matches($to)) {
                throw new InvalidOperation(sprintf('%s converts %s into %s: it doesn\'t fit field "%s".', $converter::class, $converter::from()->describe(), $converter::to()->describe(), $name));
            }
        }

        foreach ($this->initializers as $name => $initializer) {
            if (!$initializer::to()->matches($new->get(FieldName::of($name)))) {
                throw new InvalidOperation(sprintf('%s fills %s: it doesn\'t fit field "%s".', $initializer::class, $initializer::to()->describe(), $name));
            }
        }
    }

    public function describe(): string {
        return sprintf('ReplaceType(%s with %s)', $this->type, $this->with);
    }
}

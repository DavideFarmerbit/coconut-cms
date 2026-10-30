<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Captured;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\OccurrenceKind;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Values;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\EntitySchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;

/**
 * A type replaced by another: every entity of it becomes an entity of $with,
 * keeping its id, so References and owned slots holding it stay valid. Any
 * type may replace it, an ancestor, a sibling, an unrelated type or a direct
 * subtype, which takes its place in the chain; not a deeper descendant, which
 * the subtypes between them would end up extending.
 *
 * Values follow field names: a field both have, the same, keeps its value; a
 * field only the old type has goes; a field only $with has follows its
 * default, or an initializer. A field both have, but differently, is declared
 * like a retype, with a converter or a reset; one left undeclared is an
 * InvalidFixup. Every subtype is reparented onto $with the same way, and every
 * field targeting the old type, Embeds included, targets $with.
 *
 * A value holding the old type where only an ancestor of it is declared must
 * still fit once it's a $with: the checks before writing fail the migration
 * naming the entity otherwise.
 */
final readonly class ReplaceType implements Fixup, MovesValues
{
    /**
     * @param array<string, FieldInitializer> $initializers By field name: a
     *        field only $with has.
     * @param array<string, FieldConverter> $converters By field name: a field
     *        both have, differently.
     * @param list<string> $resets The fields both have, differently, whose
     *        values go: the entities follow $with's default there.
     */
    public function __construct(
        public string $type,
        public string $with,
        private array $initializers = [],
        private array $converters = [],
        private array $resets = [],
    ) {
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $old = $schemas->get($this->type);
        if ($this->with === $this->type) {
            throw new InvalidFixup(sprintf('type "%s" can\'t replace itself.', $this->type));
        }

        // A replacement new to the target comes with it: no AddType needed first.
        if (!$schemas->has($this->with)) {
            $schemas = $schemas->withTargetType($this->with);
        }

        if ($schemas->isA($this->with, $this->type) && $schemas->get($this->with)->parent !== $this->type) {
            throw new InvalidFixup(sprintf('"%s" descends from "%s" through another of its subtypes, which would end up extending it: only a direct subtype can take its place.', $this->with, $this->type));
        }

        $oldFields = $schemas->hierarchyFieldsOf($this->type);
        $with = $schemas->get($this->with);
        if ($with->parent === $this->type) {
            $schemas = $schemas->with($with->withParent($old->parent));
        }

        $schemas = $schemas->without($this->type)->map(fn (EntitySchema $schema): EntitySchema => $schema
            ->withParent($schema->parent === $this->type ? $this->with : $schema->parent)
            ->withFields($schema->fields->retargeted($this->type, $this->with)));

        $this->assertDeclared($oldFields, $schemas->hierarchyFieldsOf($this->with));
        $this->assertNoNativeClash($schemas);

        return $schemas;
    }

    /**
     * Throws unless every field both types have differently is converted or
     * reset, and every name given is one that fits: a typo never passes.
     */
    private function assertDeclared(FieldDescriptorCollection $old, FieldDescriptorCollection $new): void {
        $differing = [];
        $gained = [];
        foreach ($new as $name => $field) {
            $name = (string) $name;
            if (!$old->has(FieldName::of($name))) {
                $gained[] = $name;
            } elseif ($old->get(FieldName::of($name)) != $field) {
                $differing[] = $name;
            }
        }

        $undeclared = array_diff($differing, array_keys($this->converters), $this->resets);
        if ($undeclared !== []) {
            throw new InvalidFixup(sprintf(
                '%s differ between "%s" and "%s": give each a converter, or reset it.',
                implode(', ', array_map(static fn (string $name): string => '"' . $name . '"', $undeclared)),
                $this->type,
                $this->with,
            ));
        }

        foreach ([...array_keys($this->converters), ...$this->resets] as $name) {
            if (!in_array($name, $differing, true)) {
                throw new InvalidFixup(sprintf('"%s" isn\'t a field "%s" and "%s" both have differently: nothing to convert or reset.', $name, $this->type, $this->with));
            }
        }

        foreach (array_keys($this->initializers) as $name) {
            if (!in_array($name, $gained, true)) {
                throw new InvalidFixup(sprintf('"%s" isn\'t a field only "%s" has: nothing to initialize.', $name, $this->with));
            }
        }
    }

    /**
     * Throws when a native subtype, now under $with, declares a field $with
     * gives too. An editor-created one's clash is a break, set aside like any.
     */
    private function assertNoNativeClash(SchemaState $schemas): void {
        $inherited = $schemas->hierarchyFieldsOf($this->with);
        foreach ($schemas->schemas() as $identifier => $schema) {
            if ($schema->parent !== $this->with || $schemas->sourceOf((string) $identifier) !== SchemaSource::Native) {
                continue;
            }

            foreach ($schema->fields as $name => $field) {
                if ($inherited->has(FieldName::of((string) $name))) {
                    throw new InvalidFixup(sprintf('subtype "%s" declares "%s", which "%s" gives too.', $identifier, $name, $this->with));
                }
            }
        }
    }

    /**
     * Wherever the old type or a subtype of it lives: converts or resets the
     * fields both types have differently, initializes the ones gained, and
     * makes each entity of the old type an entity of $with.
     */
    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        foreach (Occurrences::in($values, $before) as $occurrence) {
            if (!$before->isA($occurrence->identifier, $this->type)) {
                continue;
            }

            $becomes = $occurrence->identifier === $this->type ? $this->with : $occurrence->identifier;
            $old = $before->hierarchyFieldsOf($occurrence->identifier);
            foreach ($after->hierarchyFieldsOf($becomes) as $name => $field) {
                $name = (string) $name;
                $path = $occurrence->fieldPath(FieldName::of($name));
                if (!$old->has(FieldName::of($name))) {
                    if (isset($this->initializers[$name])) {
                        $values->write($path, $field, $this->initializers[$name]->initialize($values->valuesAt($occurrence->path)));
                    }
                } elseif (in_array($name, $this->resets, true)) {
                    $values->removeAt($path);
                } elseif (isset($this->converters[$name])) {
                    $under = $values->valuesUnder($path);
                    $isDefault = $old->get(FieldName::of($name))->kind() === FieldKind::Embed ? self::allDefault($under) : $values->markOf($path)->isDefault();
                    $values->write($path, $field, $this->converters[$name]->convert(new Captured($values->get($path), $isDefault, $under)));
                }
            }

            if ($occurrence->identifier !== $this->type) {
                continue;
            }

            if ($occurrence->kind === OccurrenceKind::Root) {
                $values->identifier = $this->with;
            } elseif ($occurrence->kind === OccurrenceKind::Owned && $occurrence->path !== null) {
                $values->set($occurrence->path, $this->with, $values->markOf($occurrence->path));
            }
        }
    }

    public function movedTypes(): array {
        return [$this->type];
    }

    public function describe(): string {
        return sprintf('ReplaceType(%s → %s)', $this->type, $this->with);
    }

    /** Whether every value of $values follows its default: how an Embed with no value of its own is. */
    private static function allDefault(Values $values): bool {
        foreach ($values->paths() as $path) {
            if (!$values->isDefault($path)) {
                return false;
            }
        }

        return true;
    }
}

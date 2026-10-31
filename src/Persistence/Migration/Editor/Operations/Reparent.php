<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\LevelInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * An editor-created type moved under $parent, as Reparent moves it: an
 * editor-created type, a native one that's editorExtensible, or none. A
 * level it gains is filled with its defaults, or by $initializer; a level it
 * loses takes its values with it. Never under itself, nor under one of its
 * own descendants.
 */
final readonly class Reparent implements SchemaOperation
{
    public function __construct(
        public string $type,
        public ?string $parent,
        public ?LevelInitializer $initializer = null,
    ) {
    }

    public function applyTo(SchemaDraft $draft): void {
        $schema = $draft->editorType($this->type);
        if ($schema->parent === $this->parent) {
            throw new InvalidOperation(sprintf('"%s" extends %s already.', $this->type, $this->parent === null ? 'nothing' : '"' . $this->parent . '"'));
        }

        if ($this->parent !== null) {
            if ($draft->isA($this->parent, $this->type)) {
                throw new InvalidOperation(sprintf('"%s" is "%s" or extends it: a type can\'t extend itself.', $this->parent, $this->type));
            }

            if (!$draft->schema($this->parent)->editorExtensible) {
                throw new InvalidOperation(sprintf('"%s" doesn\'t allow editor-created subtypes.', $this->parent));
            }
        }

        $draft->replace($schema->withParent($this->parent));
        foreach ($schema->fields->names() as $name) {
            if ($this->parent !== null) {
                self::assertFree($draft, $this->parent, $name);
            }
        }

        if (!$draft->isCreated($this->type)) {
            $draft->fixup(new Fixup\Reparent($this->type, $this->initializer));
        } elseif ($this->initializer !== null) {
            throw new InvalidOperation(sprintf('Type "%s" is new in this save: it has no entries to initialize.', $this->type));
        }
    }

    /** Throws when $parent or its ancestors already have the type's own field $name. */
    private static function assertFree(SchemaDraft $draft, string $parent, FieldName $name): void {
        try {
            $draft->assertNoField($parent, $name);
        } catch (InvalidOperation $clash) {
            throw new InvalidOperation(sprintf('%s The type moving under it has one too.', $clash->getMessage()), 0, $clash);
        }
    }

    public function describe(): string {
        return sprintf('Reparent(%s under %s)', $this->type, $this->parent ?? 'nothing');
    }
}

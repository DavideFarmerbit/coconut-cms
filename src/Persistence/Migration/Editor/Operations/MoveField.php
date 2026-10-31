<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldInitializer;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\DefaultValue;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * An editor-created type's own field moved to another editor-created type
 * of its chain, as MoveField moves it. Moved up, every entry of $to gains
 * it: the type's own keep their values, the others get the default, or what
 * $initializer decides. Moved down, entries no longer of a type declaring it
 * lose their values; nobody gains it, so it takes no initializer. Either
 * way $to declares it now, so it takes $default, as AddField does.
 */
final readonly class MoveField implements SchemaOperation
{
    public FieldName $field;

    public function __construct(
        public string $type,
        string $field,
        public string $to,
        public ?DefaultValue $default,
        public ?FieldInitializer $initializer = null,
    ) {
        $this->field = FieldName::of($field);
    }

    public function applyTo(SchemaDraft $draft): void {
        $descriptor = $draft->ownField($this->type, $this->field);
        $draft->editorType($this->to);
        $up = $draft->isA($this->type, $this->to) && $this->type !== $this->to;
        if (!$up && !$draft->isA($this->to, $this->type)) {
            throw new InvalidOperation(sprintf('"%s" and "%s" aren\'t one chain: a field moves only up or down its own.', $this->type, $this->to));
        }

        if ($this->type === $this->to) {
            throw new InvalidOperation(sprintf('Field "%s" is "%s"\'s already.', $this->field, $this->to));
        }

        if (!$up && $this->initializer !== null) {
            throw new InvalidOperation(sprintf('Field "%s" moves down from "%s" to "%s": nobody gains it, so it has nothing to initialize.', $this->field, $this->type, $this->to));
        }

        if ($this->initializer !== null && !$this->initializer::to()->matches($descriptor)) {
            throw new InvalidOperation(sprintf('%s fills %s: it doesn\'t fit field "%s" of "%s".', $this->initializer::class, $this->initializer::to()->describe(), $this->field, $this->type));
        }

        $from = $draft->editorType($this->type);
        $draft->replace($from
            ->withFields($from->fields->without($this->field))
            ->withUniqueGroups(UniqueGroups::withoutField($from->uniqueGroups, $this->field)));
        $draft->assertNoField($this->to, $this->field);
        $to = $draft->editorType($this->to);
        $draft->replace($to->withFields($to->fields->with($this->field, $descriptor)));

        // A type the save creates has no entries: what moves is only what
        // the existing one holds.
        $fromCreated = $draft->isCreated($this->type);
        $toCreated = $draft->isCreated($this->to);
        $fixup = match (true) {
            !$fromCreated && !$toCreated => new Fixup\MoveField($this->type, (string) $this->field, $this->to, $this->initializer),
            !$fromCreated => new Fixup\DropField($this->type, (string) $this->field),
            !$toCreated => new Fixup\AddField($this->to, (string) $this->field, $this->initializer),
            default => null,
        };
        if ($fixup !== null) {
            $draft->fixup($fixup);
        }

        NewDefaults::pin($draft, $this->to, $descriptor, $this->default);
    }

    public function describe(): string {
        return sprintf('MoveField(%s, %s → %s)', $this->field, $this->type, $this->to);
    }
}

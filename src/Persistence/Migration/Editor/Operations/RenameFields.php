<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * Several of an editor-created type's own fields renamed at once, as
 * RenameFields renames them: every old name is read as the type has it
 * before any is renamed, so two fields swap names. Their values, defaults,
 * unique groups, rules and permissions follow. Only the result is checked:
 * no two fields end up with one name, on the type or its ancestors.
 */
final readonly class RenameFields implements SchemaOperation
{
    /** @var array<string, string> Each old name's new one. */
    public array $renames;

    /** @param array<string, string> $renames Each old name's new one; one renamed to itself is left out. */
    public function __construct(
        public string $type,
        array $renames,
    ) {
        $kept = [];
        foreach ($renames as $old => $new) {
            if ((string) $old !== $new) {
                $kept[(string) FieldName::of((string) $old)] = (string) FieldName::of($new);
            }
        }

        $this->renames = $kept;
    }

    public function applyTo(SchemaDraft $draft): void {
        if ($this->renames === []) {
            return;
        }

        foreach (array_keys($this->renames) as $old) {
            $draft->ownField($this->type, FieldName::of($old));
        }

        // A name freed by another of these renames is free; any other must be.
        $finals = [];
        foreach ($this->renames as $new) {
            if (isset($finals[$new])) {
                throw new InvalidOperation(sprintf('Two fields of "%s" would be named "%s".', $this->type, $new));
            }
            $finals[$new] = true;
            if (!isset($this->renames[$new])) {
                $draft->assertNoField($this->type, FieldName::of($new));
            }
        }

        $schema = $draft->editorType($this->type);
        $draft->replace($schema
            ->withFields($schema->fields->renamedAll($this->renames))
            ->withUniqueGroups(UniqueGroups::withFieldsRenamed($schema->uniqueGroups, $this->renames))
            ->withFieldValidators($schema->fieldValidators->renamedAll($this->renames))
            ->withFieldPermissions($schema->fieldPermissions->renamedAll($this->renames)));
        if (!$draft->isCreated($this->type)) {
            $draft->fixup(new Fixup\RenameFields($this->type, $this->renames));
        }

        $draft->fieldsRenamed($this->type, $this->renames);
    }

    public function describe(): string {
        return sprintf('RenameFields(%s, %s)', $this->type, implode(', ', array_map(static fn (string $old, string $new): string => "$old → $new", array_keys($this->renames), $this->renames)));
    }
}

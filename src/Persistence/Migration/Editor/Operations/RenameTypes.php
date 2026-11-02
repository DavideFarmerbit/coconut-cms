<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Native\NativeIdentifiers;

/**
 * Several editor-created types renamed at once, as RenameTypes renames them:
 * every old identifier is read as it is before any is renamed, so two types
 * swap names. Their content is kept, their tables follow their new
 * identifiers, and every editor-created parent and field naming one follows.
 * Only the result is checked: no two types end up with one identifier.
 */
final readonly class RenameTypes implements SchemaOperation
{
    /** @var array<string, string> Each old identifier's new one. */
    public array $renames;

    /** @param array<string, string> $renames Each old identifier's new one; one renamed to itself is left out. */
    public function __construct(array $renames) {
        $this->renames = array_filter($renames, static fn (string $new, string $old): bool => $old !== $new, ARRAY_FILTER_USE_BOTH);
    }

    public function applyTo(SchemaDraft $draft): void {
        if ($this->renames === []) {
            return;
        }

        $renamed = [];
        $finals = [];
        foreach ($this->renames as $old => $new) {
            $schema = $draft->editorType($old);
            $created = $draft->isCreated($old);
            if (isset($finals[$new])) {
                throw new InvalidOperation(sprintf('Two types would be named "%s".', $new));
            }
            $finals[$new] = true;

            // A name freed by another of these renames is free; any other must be.
            if (!isset($this->renames[$new])) {
                $draft->assertFreeName($new, $created);
            } elseif ($created) {
                throw new InvalidOperation(sprintf('"%s" named another type when this save started: save its rename or delete first.', $new));
            }

            $renamed[$old] = $schema->withIdentifier($new)->withTable(NativeIdentifiers::tableOf($new));
        }

        $stored = array_filter($this->renames, static fn (string $old): bool => !$draft->isCreated($old), ARRAY_FILTER_USE_KEY);
        $draft->renameAll($renamed);
        if ($stored !== []) {
            $draft->fixup(new Fixup\RenameTypes($stored));
        }
    }

    public function describe(): string {
        return sprintf('RenameTypes(%s)', implode(', ', array_map(static fn (string $old, string $new): string => "$old → $new", array_keys($this->renames), $this->renames)));
    }
}

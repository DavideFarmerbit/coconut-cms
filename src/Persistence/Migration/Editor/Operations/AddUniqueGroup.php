<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarType;
use XyloIsCoding\CoconutCms\Persistence\Schema\UniqueGroup;

/**
 * A unique group added over an editor-created type's own fields, as
 * AddUniqueGroup adds it: no two entries in one place may hold the same
 * values. Content already breaking it refuses the save, listing the
 * duplicates. Each field is stored in one indexable column: a Scalar but
 * Text, an indexable value object, or a Reference; an Embed isn't offered
 * yet.
 */
final readonly class AddUniqueGroup implements SchemaOperation
{
    /** @var list<string> */
    public array $fields;

    public function __construct(
        public string $type,
        string ...$fields,
    ) {
        $this->fields = array_values($fields);
    }

    public function applyTo(SchemaDraft $draft): void {
        if ($this->fields === []) {
            throw new InvalidOperation('A unique group needs at least one field.');
        }

        $group = UniqueGroup::of(...array_map(FieldName::of(...), $this->fields));
        foreach ($group as $name) {
            self::assertIndexable($draft->ownField($this->type, $name), $name);
        }

        $schema = $draft->editorType($this->type);
        if (isset(UniqueGroups::byKey($schema->uniqueGroups)[UniqueGroups::keyOf($group)])) {
            throw new InvalidOperation(sprintf('Type "%s" already has a unique group over %s.', $this->type, UniqueGroups::describe($group)));
        }

        $draft->replace($schema->withUniqueGroups([...$schema->uniqueGroups, $group]));
        if (!$draft->isCreated($this->type)) {
            $draft->fixup(new Fixup\AddUniqueGroup($this->type, ...$this->fields));
        }
    }

    /** Throws unless $field is stored in one column a unique index can cover. */
    private static function assertIndexable(FieldDescriptor $field, FieldName $name): void {
        $indexable = match ($field->kind()) {
            FieldKind::Scalar => $field->scalarType() !== ScalarType::Text,
            FieldKind::ValueObject => $field->indexable(),
            FieldKind::Reference, FieldKind::TypeReference => true,
            default => false,
        };
        if (!$indexable) {
            throw new InvalidOperation(match ($field->kind()) {
                FieldKind::Embed => sprintf('Field "%s" is an Embed: a unique group over one isn\'t offered yet.', $name),
                FieldKind::OwningReference, FieldKind::Collection => sprintf('Field "%s" has no column to index: an owned object or a Collection can\'t be in a unique group.', $name),
                default => sprintf('Field "%s" is stored as TEXT: MySQL can\'t index it whole.', $name),
            });
        }
    }

    public function describe(): string {
        return sprintf('AddUniqueGroup(%s, %s)', $this->type, implode(', ', $this->fields));
    }
}

<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;

/**
 * An editor-created type's own field, or its Collection's items, holding
 * null from now on. Every value stored stays valid, so it takes no fixup.
 */
final readonly class MakeOptional implements SchemaOperation
{
    public FieldName $field;

    public function __construct(
        public string $type,
        string $field,
    ) {
        $this->field = FieldName::of($field);
    }

    public function applyTo(SchemaDraft $draft): void {
        $field = $draft->ownField($this->type, $this->field);
        if ($field->ownOrItemNullability() !== Nullability::NotNullable) {
            throw new InvalidOperation(sprintf('Field "%s" of "%s" already holds null, or has no nullability to change.', $this->field, $this->type));
        }

        $schema = $draft->editorType($this->type);
        $draft->replace($schema->withFields($schema->fields->with($this->field, $field->withNullability(Nullability::Nullable))));
    }

    public function describe(): string {
        return sprintf('MakeOptional(%s, %s)', $this->type, $this->field);
    }
}

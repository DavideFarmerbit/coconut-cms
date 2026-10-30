<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\Nullability;

/**
 * An editor-created type's own field, or its Collection's items, no longer
 * holding null, as MakeNotNullable makes it: an entry holding null follows
 * the default, a null item goes. A Reference is always nullable.
 */
final readonly class MakeRequired implements SchemaOperation
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
        if ($field->ownOrItemNullability() !== Nullability::Nullable) {
            throw new InvalidOperation(sprintf('Field "%s" of "%s" holds no null to refuse.', $this->field, $this->type));
        }

        try {
            $required = $field->withNullability(Nullability::NotNullable);
        } catch (InvalidArgumentException $refused) {
            throw new InvalidOperation(sprintf('Field "%s" of "%s": %s', $this->field, $this->type, $refused->getMessage()), 0, $refused);
        }

        $schema = $draft->editorType($this->type);
        $draft->replace($schema->withFields($schema->fields->with($this->field, $required)));
        if (!$draft->isCreated($this->type)) {
            $draft->fixup(new Fixup\MakeNotNullable($this->type, (string) $this->field));
        }
    }

    public function describe(): string {
        return sprintf('MakeRequired(%s, %s)', $this->type, $this->field);
    }
}

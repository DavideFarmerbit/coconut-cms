<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use XyloIsCoding\CoconutCms\Persistence\Migration\Data\FieldConverter;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;

/**
 * An editor-created type's own field holding something else: $field, named
 * as the field it replaces. $converter turns every stored value into a new
 * one; without one, every value is dropped and every entry follows the new
 * default. A type this save creates has no values, so it takes no converter.
 */
final readonly class RetypeField implements SchemaOperation
{
    public function __construct(
        public string $type,
        public FieldDescriptor $field,
        public ?FieldConverter $converter = null,
    ) {
    }

    public function applyTo(SchemaDraft $draft): void {
        $name = FieldName::of($this->field->name());
        if ($draft->ownField($this->type, $name) == $this->field) {
            throw new InvalidOperation(sprintf('Field "%s" of "%s" already holds that.', $name, $this->type));
        }

        $created = $draft->isCreated($this->type);
        if ($created && $this->converter !== null) {
            throw new InvalidOperation(sprintf('Type "%s" is new in this save: it has no values to convert.', $this->type));
        }

        $schema = $draft->editorType($this->type);
        $draft->replace($schema->withFields($schema->fields->with($name, $this->field)));
        if (!$created) {
            $draft->fixup($this->converter === null
                ? Fixup\RetypeField::reset($this->type, (string) $name)
                : Fixup\RetypeField::converting($this->type, (string) $name, $this->converter));
        }
    }

    public function describe(): string {
        return sprintf('RetypeField(%s, %s%s)', $this->type, $this->field->name(), $this->converter === null ? ', reset' : ', ' . $this->converter::class);
    }
}

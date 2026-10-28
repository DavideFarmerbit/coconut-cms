<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Fixup;

use XyloIsCoding\CoconutCms\Persistence\Entity\Identity\EntityIds;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\AggregateValues;
use XyloIsCoding\CoconutCms\Persistence\Migration\Data\Occurrences;
use XyloIsCoding\CoconutCms\Persistence\Migration\Diff\UniqueGroups;
use XyloIsCoding\CoconutCms\Persistence\Migration\InvalidFixup;
use XyloIsCoding\CoconutCms\Persistence\Migration\SchemaState;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\BrokenField;
use XyloIsCoding\CoconutCms\Persistence\Schema\NoType\BrokenFieldReason;

/**
 * A field of an editor-created type that broke, set aside: it leaves the
 * type's fields when the type declares it, a broken-field record takes its
 * place on the type's schema, and every entity's values for it are captured
 * under that record and removed. What a looser policy does with a break; the
 * planner places it just before the fixup that caused the break.
 */
final readonly class SetAsideField implements Fixup, MovesValues
{
    public FieldName $field;

    /** The record's id, made once, so the schema and every capture share it. */
    public string $recordId;

    /**
     * @param string $type The type that loses the field.
     * @param string $origin The type that declared it: $type, or the ancestor cut off.
     */
    public function __construct(
        public string $type,
        string $field,
        public BrokenFieldReason $reason,
        public string $origin,
    ) {
        $this->field = FieldName::of($field);
        $this->recordId = EntityIds::generate();
    }

    public function applyTo(SchemaState $schemas): SchemaState {
        $fields = $schemas->hierarchyFieldsOf($this->type);
        if (!$fields->has($this->field)) {
            throw new InvalidFixup(sprintf('type "%s" has no field "%s" to set aside at this point.', $this->type, $this->field));
        }

        $schema = $schemas->get($this->type);
        $record = new BrokenField($this->recordId, (string) $this->field, $fields->get($this->field), $this->origin, $this->reason);
        // An inherited field leaves with its ancestor: only one the type declares is removed here.
        if ($schema->fields->has($this->field)) {
            $schema = $schema
                ->withFields($schema->fields->without($this->field))
                ->withUniqueGroups(UniqueGroups::withoutField($schema->uniqueGroups, $this->field));
        }

        return $schemas->with($schema->withNoTypeRecord($record));
    }

    /** Captures and removes the field's values wherever the type lives: its entities and its subtypes', owned or not, and its Embed sites. */
    public function moveValues(AggregateValues $values, SchemaState $before, SchemaState $after): void {
        foreach (Occurrences::ofType($this->type, $values, $before) as $occurrence) {
            $values->setAside($this->recordId, $occurrence, $this->field);
        }
    }

    public function movedTypes(): array {
        return [$this->type];
    }

    public function describe(): string {
        return sprintf('SetAsideField(%s, %s, %s)', $this->type, $this->field, $this->reason->value);
    }
}

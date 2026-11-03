<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration;

use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\StoredSchema;
use XyloIsCoding\CoconutCms\Persistence\Schema\SchemaSource;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\Broken\BrokenRule;

/**
 * A rule an earlier migration kept broken on an editor-created type: it
 * checks nothing until it's fixed or removed. A MigrationReport warns about
 * each one every time, never blocking.
 */
final readonly class KeptBrokenRule
{
    /**
     * @param string|null $field The field it checks; null for a cross-field rule.
     * @param string $rule The identifier it was stored under.
     */
    public function __construct(
        public string $type,
        public ?string $field,
        public string $rule,
        public string $reason,
    ) {
    }

    /**
     * Every rule kept broken on the editor-created types of $stored.
     * @param list<StoredSchema> $stored
     * @return list<self>
     */
    public static function allIn(array $stored): array {
        $kept = [];
        foreach ($stored as $each) {
            if ($each->source !== SchemaSource::Editor) {
                continue;
            }

            $schema = $each->schema;
            foreach ($schema->fieldValidators as $name => $rules) {
                foreach ($rules as $rule) {
                    if ($rule instanceof BrokenRule && $rule->wasKept()) {
                        $kept[] = new self($schema->identifier, (string) $name, (string) ($rule->entry()['rule'] ?? '?'), $rule->reason());
                    }
                }
            }

            foreach ($schema->prototypeValidators as $rule) {
                if ($rule instanceof BrokenRule && $rule->wasKept()) {
                    $kept[] = new self($schema->identifier, null, (string) ($rule->entry()['rule'] ?? '?'), $rule->reason());
                }
            }
        }

        return $kept;
    }

    /** How a report warns about it. */
    public function describe(): string {
        $place = $this->field === null
            ? sprintf('Cross-field rule "%s" of editor-created type "%s"', $this->rule, $this->type)
            : sprintf('Rule "%s" on field "%s" of editor-created type "%s"', $this->rule, $this->field, $this->type);

        return sprintf('%s is kept broken and checks nothing: %s', $place, $this->reason);
    }
}

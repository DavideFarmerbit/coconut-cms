<?php

namespace XyloIsCoding\CoconutCms\Persistence\Migration\Editor\Operations;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\InvalidOperation;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaDraft;
use XyloIsCoding\CoconutCms\Persistence\Migration\Editor\SchemaOperation;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\PrototypeValidatorRule;

/**
 * An editor-created type given its full list of cross-field rules, in order,
 * replacing the ones it had; none clears them. Each checks its configuration
 * against the type's own fields. Only the definition changes: content
 * breaking a rule is caught on its next save.
 */
final readonly class SetPrototypeRules implements SchemaOperation
{
    /** @var list<PrototypeValidatorRule> */
    public array $rules;

    public function __construct(
        public string $type,
        PrototypeValidatorRule ...$rules,
    ) {
        $this->rules = array_values($rules);
    }

    public function applyTo(SchemaDraft $draft): void {
        $schema = $draft->editorType($this->type);
        foreach ($this->rules as $index => $rule) {
            try {
                $rule->checkUsage($schema->fields);
            } catch (InvalidArgumentException $refused) {
                throw new InvalidOperation(sprintf('Rule %d: %s', $index, $refused->getMessage()), 0, $refused);
            }
        }

        $draft->replace($schema->withPrototypeValidators($this->rules));
    }

    public function describe(): string {
        return sprintf('SetPrototypeRules(%s, %d)', $this->type, count($this->rules));
    }
}

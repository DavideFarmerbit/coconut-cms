<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;

/**
 * A name-to-class registry of FieldValidatorRule classes, so an editor-created field
 * can pick a rule by name instead of by PHP class reference.
 */
final class FieldValidatorRegistry
{
    /** @var array<string, class-string<FieldValidatorRule>> */
    private array $rules = [];

    /**
     * @param class-string<FieldValidatorRule> $ruleClass
     */
    public function register(Identifier $id, string $ruleClass): void {
        $key = (string) $id;

        if (isset($this->rules[$key])) {
            throw new RuntimeException("Field validator rule \"{$key}\" is already registered.");
        }

        if (!is_subclass_of($ruleClass, FieldValidatorRule::class)) {
            throw new RuntimeException("\"{$ruleClass}\" does not implement " . FieldValidatorRule::class . '.');
        }

        $this->rules[$key] = $ruleClass;
    }

    /** @return class-string<FieldValidatorRule> */
    public function resolve(Identifier $id): string {
        $key = (string) $id;

        if (!isset($this->rules[$key])) {
            throw new RuntimeException("Unknown field validator rule \"{$key}\": nothing was registered under it.");
        }

        return $this->rules[$key];
    }
}

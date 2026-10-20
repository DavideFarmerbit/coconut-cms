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

    /**
     * The identifier $ruleClass was registered under: how a stored schema names
     * a rule, so renaming or moving the class doesn't break what's stored.
     * @param class-string<FieldValidatorRule> $ruleClass
     */
    public function identifierOf(string $ruleClass): Identifier {
        $key = array_search($ruleClass, $this->rules, true);
        if ($key === false) {
            throw new RuntimeException("\"{$ruleClass}\" was never registered, so it has no identifier to be stored under.");
        }

        return Identifier::fromString($key);
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

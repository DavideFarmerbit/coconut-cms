<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;

/**
 * A name-to-class registry of PrototypeValidatorRule classes, so an editor-created
 * shape can pick a cross-field rule by name instead of by PHP class reference.
 */
final class PrototypeValidatorRegistry
{
    /** @var array<string, class-string<PrototypeValidatorRule>> */
    private array $rules = [];

    /**
     * @param class-string<PrototypeValidatorRule> $ruleClass
     */
    public function register(Identifier $id, string $ruleClass): void {
        $key = (string) $id;

        if (isset($this->rules[$key])) {
            throw new RuntimeException("Prototype validator rule \"{$key}\" is already registered.");
        }

        if (!is_subclass_of($ruleClass, PrototypeValidatorRule::class)) {
            throw new RuntimeException("\"{$ruleClass}\" does not implement " . PrototypeValidatorRule::class . '.');
        }

        $this->rules[$key] = $ruleClass;
    }

    /** @return class-string<PrototypeValidatorRule> */
    public function resolve(Identifier $id): string {
        $key = (string) $id;

        if (!isset($this->rules[$key])) {
            throw new RuntimeException("Unknown prototype validator rule \"{$key}\": nothing was registered under it.");
        }

        return $this->rules[$key];
    }
}

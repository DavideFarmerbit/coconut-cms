<?php

namespace XyloIsCoding\CoconutCms\Persistence\Schema\Validation;

use InvalidArgumentException;
use RuntimeException;
use XyloIsCoding\CoconutCms\Core\Identifier;
use XyloIsCoding\CoconutCms\Persistence\Parameters\ConstructorShape;
use XyloIsCoding\CoconutCms\Persistence\Parameters\InvalidParameters;
use XyloIsCoding\CoconutCms\Persistence\Parameters\ParameterRegistries;

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

    /**
     * The identifier $ruleClass was registered under: how a stored schema names
     * a rule, so renaming or moving the class doesn't break what's stored.
     * @param class-string<PrototypeValidatorRule> $ruleClass
     */
    public function identifierOf(string $ruleClass): Identifier {
        $key = array_search($ruleClass, $this->rules, true);
        if ($key === false) {
            throw new RuntimeException("\"{$ruleClass}\" was never registered, so it has no identifier to be stored under.");
        }

        return Identifier::fromString($key);
    }

    /**
     * Every rule registered, by identifier, in the order registered: what the
     * editor offers.
     * @return array<string, class-string<PrototypeValidatorRule>>
     */
    public function all(): array {
        return $this->rules;
    }

    /** @return class-string<PrototypeValidatorRule> */
    public function resolve(Identifier $id): string {
        $key = (string) $id;

        if (!isset($this->rules[$key])) {
            throw new RuntimeException("Unknown prototype validator rule \"{$key}\": nothing was registered under it.");
        }

        return $this->rules[$key];
    }

    /**
     * The rule $entry names, `{"rule": identifier, "config": parameters}`,
     * built from its parameters: how a stored schema and an editor save give
     * one.
     * @throws InvalidParameters When $entry isn't one, names no registered rule, or its parameters don't fit.
     */
    public function build(mixed $entry, ParameterRegistries $registries): PrototypeValidatorRule {
        if (!is_array($entry) || !is_string($entry['rule'] ?? null) || !is_array($entry['config'] ?? [])) {
            throw new InvalidParameters(null, 'A rule is given as {"rule": identifier, "config": {...}}.');
        }

        try {
            $class = $this->resolve(Identifier::fromString($entry['rule']));
        } catch (InvalidArgumentException|RuntimeException) {
            throw new InvalidParameters(null, sprintf('No prototype rule is registered as "%s".', $entry['rule']));
        }

        try {
            return ConstructorShape::of($class)->build($entry['config'] ?? [], $registries);
        } catch (InvalidParameters $refused) {
            throw new InvalidParameters($refused->parameter === null ? 'config' : 'config.' . $refused->parameter, $refused->getMessage());
        } catch (InvalidArgumentException $refused) {
            throw new InvalidParameters('config', $refused->getMessage());
        }
    }

    /**
     * $rule as build() takes it.
     * @return array{rule: string, config: array<string, mixed>}
     */
    public function entryOf(PrototypeValidatorRule $rule, ParameterRegistries $registries): array {
        return ['rule' => (string) $this->identifierOf($rule::class), 'config' => ConstructorShape::of($rule::class)->parametersOf($rule, $registries)];
    }
}

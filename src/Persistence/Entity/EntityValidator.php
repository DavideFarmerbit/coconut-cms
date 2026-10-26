<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntitySnapshot;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Runs every rule an aggregate's types declare, before it's saved: each
 * type's prototype rules on its entity, each field's rules on its value, then
 * the same inside every Embed, owned object and Collection item, with their
 * own types' rules. Every value counts, touched or not: it's what's stored.
 */
final readonly class EntityValidator
{
    public function __construct(
        private SchemaLookup $schemas,
    ) {
    }

    /** Throws ValidationFailed, holding every error, unless $values break no rule. */
    public function assertValid(EntityValues $values): void {
        $errors = $this->errorsOf($values);
        if ($errors !== []) {
            throw new ValidationFailed($values->identifier, $errors);
        }
    }

    /** @return array<string, list<string>> Each path's errors; "" for the entity itself. */
    public function errorsOf(EntityValues $values): array {
        $errors = [];
        $this->check(EntitySnapshot::of($this->schemas, $values->identifier, $values->dense), null, FieldPath::TRAVERSE, $errors);

        return $errors;
    }

    /**
     * Checks the entity $view shows, at $root in the aggregate, and
     * everything inside it.
     * @param string $join How $root joins to its fields.
     * @param array<string, list<string>> $errors Filled in.
     */
    private function check(EntityView $view, ?FieldPath $root, string $join, array &$errors): void {
        foreach ($this->chainOf($view->type()) as $type) {
            $schema = $this->schemas->get($type);

            foreach ($schema->prototypeValidators as $rule) {
                $result = $rule->validate($view);
                if (!$result->isValid()) {
                    $errors[$root === null ? '' : (string) $root][] = $result->error();
                }
            }

            foreach ($schema->fields as $name => $field) {
                $at = $root === null ? FieldPath::of($name) : $root->append($join, FieldPath::of($name));
                $value = $view->get((string) $name);
                $isCollection = $field->kind() === FieldKind::Collection;
                // A Collection's rules, and what's inside it, apply to each item.
                $places = $isCollection
                    ? array_map(static fn (int $index): FieldPath => $at->traverse($index), array_keys($value))
                    : [$at];
                $placed = $isCollection ? $value : [$value];

                $rules = $schema->fieldValidators->has($name) ? $schema->fieldValidators->get($name) : [];
                foreach ($placed as $index => $each) {
                    foreach ($rules as $rule) {
                        $result = $rule->validate($each);
                        if (!$result->isValid()) {
                            $errors[(string) $places[$index]][] = $result->error();
                        }
                    }

                    if ($each instanceof EntityView) {
                        $isEmbed = ($isCollection ? $field->itemDescriptor() : $field)->kind() === FieldKind::Embed;
                        $this->check($each, $places[$index], $isEmbed && !$isCollection ? FieldPath::EMBED : FieldPath::TRAVERSE, $errors);
                    }
                }
            }
        }
    }

    /** @return list<string> $type and its ancestors, root first. */
    private function chainOf(string $type): array {
        $chain = [];
        for ($current = $type; $current !== null; $current = $this->schemas->get($current)->parent) {
            $chain[] = $current;
        }

        return array_reverse($chain);
    }
}

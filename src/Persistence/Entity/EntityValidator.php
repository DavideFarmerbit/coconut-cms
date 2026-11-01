<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity;

use LogicException;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntitySnapshot;
use XyloIsCoding\CoconutCms\Persistence\Entity\Values\EntityValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldName;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Validation\ValidationResult;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;

/**
 * Runs every rule an aggregate's types declare, before it's saved: each
 * type's prototype rules on its entity, each field's rules on its whole
 * value, then the same inside every Embed, owned object and Collection item,
 * with their own types' rules. Every value counts, touched or not: it's what's
 * stored.
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
                $this->place($rule->validate($view), $root, $join, $view, $errors);
            }

            foreach ($schema->fields as $name => $field) {
                $at = $root === null ? FieldPath::of($name) : $root->append($join, FieldPath::of($name));
                $value = $view->get((string) $name);
                $isCollection = $field->kind() === FieldKind::Collection;
                $fieldJoin = $field->kind() === FieldKind::Embed ? FieldPath::EMBED : FieldPath::TRAVERSE;

                // A field's rules check its whole value: a Collection's, the list.
                $rules = $schema->fieldValidators->has($name) ? $schema->fieldValidators->get($name) : [];
                foreach ($rules as $rule) {
                    $this->place($rule->validate($value), $at, $fieldJoin, $value, $errors);
                }

                // What's inside it is checked with its own types' rules.
                foreach ($isCollection ? $value : [$value] as $index => $each) {
                    if ($each instanceof EntityView) {
                        $this->check($each, $isCollection ? $at->traverse($index) : $at, $isCollection ? FieldPath::TRAVERSE : $fieldJoin, $errors);
                    }
                }
            }
        }
    }

    /**
     * Files $result's errors under $at, and those inside it under their own
     * paths: an item by its index, a field joined as its kind says.
     * @param ?FieldPath $at Where what was checked lies; null for the root entity.
     * @param string $join How $at joins to its fields.
     * @param mixed $value What was checked: an item or field inside it is looked up in it.
     * @param array<string, list<string>> $errors Filled in.
     */
    private function place(ValidationResult $result, ?FieldPath $at, string $join, mixed $value, array &$errors): void {
        foreach ($result->errors() as $error) {
            $errors[$at === null ? '' : (string) $at][] = $error;
        }

        foreach ($result->inside() as [$segment, $inner]) {
            if (is_int($segment)) {
                if ($at === null) {
                    throw new LogicException('An entity has no items: a rule placed an error at an index on it.');
                }

                // A Collection item's own fields always join with TRAVERSE.
                $this->place($inner, $at->traverse($segment), FieldPath::TRAVERSE, is_array($value) ? $value[$segment] ?? null : null, $errors);
                continue;
            }

            $next = $at === null ? FieldPath::of($segment) : $at->append($join, FieldPath::of($segment));
            $inside = $value instanceof EntityView && $value->has((string) $segment) ? $value->get((string) $segment) : null;
            $this->place($inner, $next, $this->joinOf($value, $segment), $inside, $errors);
        }
    }

    /** How the field $name of $value joins to its own fields: EMBED for an Embed, TRAVERSE otherwise. */
    private function joinOf(mixed $value, FieldName $name): string {
        if ($value instanceof EntityView) {
            foreach ($this->chainOf($value->type()) as $type) {
                $fields = $this->schemas->get($type)->fields;
                if ($fields->has($name)) {
                    return $fields->get($name)->kind() === FieldKind::Embed ? FieldPath::EMBED : FieldPath::TRAVERSE;
                }
            }
        }

        return FieldPath::TRAVERSE;
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

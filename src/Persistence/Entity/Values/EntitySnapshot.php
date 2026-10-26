<?php

namespace XyloIsCoding\CoconutCms\Persistence\Entity\Values;

use InvalidArgumentException;
use XyloIsCoding\CoconutCms\Persistence\Entity\EntityView;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptor;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldDescriptorCollection;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\FieldKind;
use XyloIsCoding\CoconutCms\Persistence\Schema\Field\ScalarValues;
use XyloIsCoding\CoconutCms\Persistence\Schema\Registry\SchemaLookup;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldPath;
use XyloIsCoding\CoconutCms\Persistence\Schema\Values\FieldValues;

/**
 * An EntityView of values in stored form: an aggregate's, about to be saved,
 * or one place in it, an Embed or an owned object. Converts each value to the
 * form a property holds it in only when it's read, the way a load does.
 */
final readonly class EntitySnapshot implements EntityView
{
    /**
     * @param FieldValues $values The whole aggregate's values, dense, keyed by
     *        path from its root.
     * @param FieldPath|null $root Where this view sits in the aggregate; null
     *        for the root itself.
     * @param string $join How $root joins to its fields: FieldPath::EMBED for
     *        a singular Embed, FieldPath::TRAVERSE otherwise.
     */
    private function __construct(
        private SchemaLookup $schemas,
        private FieldValues $values,
        private string $type,
        private ?FieldPath $root,
        private string $join,
    ) {
    }

    /** A view of the $identifier entity $values are. */
    public static function of(SchemaLookup $schemas, string $identifier, FieldValues $values): self {
        return new self($schemas, $values, $identifier, null, FieldPath::TRAVERSE);
    }

    public function type(): string {
        return $this->type;
    }

    public function isA(string $type): bool {
        for ($current = $this->type; $current !== null; $current = $this->schemas->get($current)->parent) {
            if ($current === $type) {
                return true;
            }
        }

        return false;
    }

    public function has(string $path): bool {
        try {
            $this->resolve($path);
        } catch (InvalidArgumentException) {
            return false;
        }

        return true;
    }

    public function get(string $path): mixed {
        [$field, $at, $isItem] = $this->resolve($path);

        return $this->form($field, $at, $isItem);
    }

    /**
     * The field $path leads to from this view, its path from the aggregate's
     * root, and whether it's a Collection's item. Throws when it leads to no
     * value.
     * @return array{FieldDescriptor, FieldPath, bool}
     */
    private function resolve(string $path): array {
        $rest = FieldPath::fromString($path);
        $fields = $this->schemas->hierarchyFieldsOf($this->type);
        $at = $this->root;
        $join = $this->join;

        while (true) {
            $name = $rest->head();
            if (is_int($name) || !$fields->has($name)) {
                throw self::nothingAt($this->type, $path);
            }

            $field = $fields->get($name);
            $at = $at === null ? FieldPath::of($name) : $at->append($join, FieldPath::of($name));
            $next = $rest->tail();
            if ($next === null) {
                return [$field, $at, false];
            }

            $stepJoin = $rest->headJoin();
            if ($field->kind() === FieldKind::Embed && $stepJoin === FieldPath::EMBED) {
                [$fields, $join] = [$this->schemas->hierarchyFieldsOf($field->target()), FieldPath::EMBED];
            } elseif ($field->kind() === FieldKind::OwningReference && $stepJoin === FieldPath::TRAVERSE) {
                [$fields, $join] = [$this->schemas->hierarchyFieldsOf($this->ownedType($at, $path)), FieldPath::TRAVERSE];
            } elseif ($field->kind() === FieldKind::Collection && $stepJoin === FieldPath::TRAVERSE && is_int($next->head())) {
                if ($next->head() >= (int) $this->values->get($at)) {
                    throw self::nothingAt($this->type, $path);
                }

                $item = $field->itemDescriptor();
                $at = $at->traverse($next->head());
                $next = $next->tail();
                if ($next === null) {
                    return [$item, $at, true];
                }

                $fields = $this->itemFields($item, $at, $path);
                $join = FieldPath::TRAVERSE;
            } else {
                throw self::nothingAt($this->type, $path);
            }

            $rest = $next;
        }
    }

    /** The fields of the item at $at: an Embed item's, or an owned item's. */
    private function itemFields(FieldDescriptor $item, FieldPath $at, string $path): FieldDescriptorCollection {
        return match ($item->kind()) {
            FieldKind::Embed => $this->schemas->hierarchyFieldsOf($item->target()),
            FieldKind::OwningReference => $this->schemas->hierarchyFieldsOf($this->ownedType($at, $path)),
            default => throw self::nothingAt($this->type, $path),
        };
    }

    /** The concrete type of the owned object at $at; throws for an empty slot. */
    private function ownedType(FieldPath $at, string $path): string {
        return $this->values->get($at) ?? throw new InvalidArgumentException(sprintf('"%s" has no value at "%s": "%s" is empty.', $this->type, $path, $at));
    }

    /**
     * $field's value at $at, as a property holds it. An Embed item's fields
     * are traversed, never embedded: each item is its own row.
     */
    private function form(FieldDescriptor $field, FieldPath $at, bool $isItem): mixed {
        $stored = $field->kind() === FieldKind::Embed ? null : $this->values->get($at);

        return match ($field->kind()) {
            FieldKind::Scalar => ScalarValues::fromStorage($field->scalarType(), $field->enumClass(), $stored),
            FieldKind::ValueObject => $stored === null ? null : $field->target()::fromStorage($stored),
            FieldKind::Reference => $stored,
            FieldKind::Embed => new self($this->schemas, $this->values, $field->target(), $at, $isItem ? FieldPath::TRAVERSE : FieldPath::EMBED),
            FieldKind::OwningReference => $stored === null ? null : new self($this->schemas, $this->values, $stored, $at, FieldPath::TRAVERSE),
            FieldKind::Collection => array_map(
                fn (int $index): mixed => $this->form($field->itemDescriptor(), $at->traverse($index), true),
                (int) $stored > 0 ? range(0, (int) $stored - 1) : [],
            ),
        };
    }

    private static function nothingAt(string $type, string $path): InvalidArgumentException {
        return new InvalidArgumentException(sprintf('"%s" has no value at "%s".', $type, $path));
    }
}
